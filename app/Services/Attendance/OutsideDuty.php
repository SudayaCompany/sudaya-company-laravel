<?php

namespace App\Services\Attendance;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogInput;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tugas di luar outlet (PRD §15, §69): staf mengajukan tanggal + jam, lokasi, tujuan,
 * keterangan, dan bukti opsional; Manager/Owner (attendance.approve_outside_duty, bukan
 * pemohon) memutuskan. Selama jendela tugas yang disetujui, radius outlet tidak berlaku
 * saat absen — GPS, akurasi, dan selfie tetap wajib dan dicatat.
 */
final class OutsideDuty
{
    /** Pengajuan paling jauh sekian hari ke depan. */
    public const MAX_DAYS_AHEAD = 60;

    public const STATUS_LABELS = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'];

    public function __construct(private AuditLogger $audit, private AttendancePhotoStorage $photos, private AttendanceEngine $engine) {}

    /** Tugas luar disetujui yang sedang berlangsung (waktu database). */
    public static function active(string $employeeId): ?object
    {
        return DB::table('outside_duty_requests')
            ->join('approvals', 'approvals.id', '=', 'outside_duty_requests.approval_id')
            ->where('outside_duty_requests.employee_id', $employeeId)
            ->where('approvals.status', 'approved')
            ->whereRaw('outside_duty_requests.starts_at <= now()')
            ->whereRaw('outside_duty_requests.ends_at > now()')
            ->orderBy('outside_duty_requests.starts_at')
            ->first(['outside_duty_requests.id', 'outside_duty_requests.location', 'outside_duty_requests.ends_at']);
    }

    /** @return array{requestId: string, approvalId: string} */
    public function request(User $actor, array $raw, mixed $photo, AuditContext $context): array
    {
        $input = AttendanceInput::outsideDuty($raw);
        $employee = $this->engine->requireSelf($actor, 'attendance.checkin');
        $tz = $employee->timezone;
        $window = DB::selectOne(
            'select ((?::date + ?::time) at time zone ?) as starts_at, ((?::date + ?::time) at time zone ?) as ends_at,
                ?::date < (now() at time zone ?)::date as in_past, ?::date > (now() at time zone ?)::date + ?::int as too_far,
                ((?::date + ?::time) at time zone ?) <= now() as ended',
            [
                $input['dutyDate'], $input['startTime'], $tz, $input['dutyDate'], $input['endTime'], $tz,
                $input['dutyDate'], $tz, $input['dutyDate'], $tz, self::MAX_DAYS_AHEAD,
                $input['dutyDate'], $input['endTime'], $tz,
            ],
        );
        if ($window->in_past || $window->ended) {
            throw new AccessException('invalid', 'Waktu tugas luar sudah lewat. Untuk absen yang terlewat, ajukan koreksi absen.');
        }
        if ($window->too_far) {
            throw new AccessException('invalid', 'Tugas luar bisa diajukan paling jauh '.self::MAX_DAYS_AHEAD.' hari ke depan.');
        }

        $evidence = $photo instanceof UploadedFile ? AttendancePhotos::read($photo) : null;
        $path = $evidence === null ? null
            : "outside-duty/{$employee->company_id}/{$employee->id}/".Str::uuid().".{$evidence['ext']}";
        if ($evidence !== null) {
            $this->photos->upload($path, $evidence['bytes'], $evidence['contentType']);
        }

        try {
            return DB::transaction(function () use ($actor, $employee, $input, $window, $path, $context) {
                // Kunci baris karyawan: dua pengajuan bersamaan tidak lolos cek tumpang tindih.
                DB::selectOne('select id from employees where id = ? for update', [$employee->id]);
                $overlap = DB::table('outside_duty_requests')
                    ->join('approvals', 'approvals.id', '=', 'outside_duty_requests.approval_id')
                    ->where('outside_duty_requests.employee_id', $employee->id)
                    ->whereIn('approvals.status', ['pending', 'approved'])
                    ->whereRaw('outside_duty_requests.starts_at < ?::timestamptz', [$window->ends_at])
                    ->whereRaw('outside_duty_requests.ends_at > ?::timestamptz', [$window->starts_at])
                    ->exists();
                if ($overlap) {
                    throw new AccessException('conflict', 'Sudah ada pengajuan tugas luar pada jam tersebut.');
                }
                $requestId = DB::table('outside_duty_requests')->insertGetId([
                    'company_id' => $employee->company_id,
                    'brand_id' => $employee->brand_id,
                    'outlet_id' => $employee->outlet_id,
                    'employee_id' => $employee->id,
                    'duty_date' => $input['dutyDate'],
                    'starts_at' => $window->starts_at,
                    'ends_at' => $window->ends_at,
                    'location' => $input['location'],
                    'purpose' => $input['purpose'],
                    'notes' => $input['notes'],
                    'evidence_path' => $path,
                    'created_by' => $actor->id,
                ]);
                $approvalId = DB::table('approvals')->insertGetId([
                    'company_id' => $employee->company_id,
                    'brand_id' => $employee->brand_id,
                    'outlet_id' => $employee->outlet_id,
                    'type' => 'outside_duty',
                    'reference_id' => $requestId,
                    'reason' => mb_substr("{$input['purpose']} — {$input['location']}", 0, 500),
                    'requested_by' => $actor->id,
                ]);
                DB::table('outside_duty_requests')->where('id', $requestId)->update(['approval_id' => $approvalId]);
                $this->audit->record([
                    'company_id' => $employee->company_id,
                    'brand_id' => $employee->brand_id,
                    'outlet_id' => $employee->outlet_id,
                    'actor_id' => $actor->id,
                    'action' => 'outside_duty.requested',
                    'entity_type' => 'outside_duty',
                    'entity_id' => $requestId,
                    'after' => ['dutyDate' => $input['dutyDate'], 'start' => $input['startTime'], 'end' => $input['endTime'], 'location' => $input['location'], 'approvalId' => $approvalId, 'evidence' => $path !== null],
                    'reason' => $input['notes'],
                ], $context);

                return ['requestId' => $requestId, 'approvalId' => $approvalId];
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                try {
                    $this->photos->remove([$path]);
                } catch (Throwable) {
                    // Bukti yatim dibersihkan oleh retensi; kegagalan hapus tidak menutupi error asli.
                }
            }
            throw $error;
        }
    }

    /** Staf membatalkan pengajuannya sendiri yang belum diputuskan. */
    public function cancel(User $actor, mixed $requestId, AuditContext $context): void
    {
        $id = CatalogInput::uuid($requestId);
        $employee = $this->engine->requireSelf($actor, 'attendance.checkin');
        DB::transaction(function () use ($actor, $employee, $id, $context) {
            $request = DB::table('outside_duty_requests')->where('id', $id)->where('employee_id', $employee->id)->first(['id', 'approval_id'])
                ?? throw AccessException::notFound();
            $cancelled = DB::selectOne(
                "update approvals set status = 'cancelled', decided_at = clock_timestamp(), updated_at = now() where id = ? and status = 'pending' returning id",
                [$request->approval_id],
            ) ?? throw new AccessException('conflict', 'Pengajuan sudah diputuskan dan tidak bisa dibatalkan.');
            $this->audit->record([
                'company_id' => $employee->company_id,
                'brand_id' => $employee->brand_id,
                'outlet_id' => $employee->outlet_id,
                'actor_id' => $actor->id,
                'action' => 'outside_duty.cancelled',
                'entity_type' => 'outside_duty',
                'entity_id' => $request->id,
                'after' => ['approvalId' => $cancelled->id],
            ], $context);
        });
    }

    /** Pengajuan milik sendiri: yang belum lewat + 30 hari terakhir. */
    public function mine(User $actor): array
    {
        $employee = $this->engine->self($actor);
        if ($employee === null) {
            return [];
        }

        return DB::table('outside_duty_requests')
            ->join('approvals', 'approvals.id', '=', 'outside_duty_requests.approval_id')
            ->leftJoin('users as decider', 'decider.id', '=', 'approvals.decided_by')
            ->where('outside_duty_requests.employee_id', $employee->id)
            ->whereRaw('outside_duty_requests.ends_at > now() - interval \'30 days\'')
            ->orderByDesc('outside_duty_requests.starts_at')
            ->limit(50)
            ->get([
                'outside_duty_requests.id', 'outside_duty_requests.duty_date', 'outside_duty_requests.starts_at', 'outside_duty_requests.ends_at',
                'outside_duty_requests.location', 'outside_duty_requests.purpose', 'outside_duty_requests.notes', 'outside_duty_requests.evidence_path',
                'approvals.status', 'approvals.decision_note', 'decider.full_name as decided_by_name',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'dutyDate' => $r->duty_date,
                'startsAt' => AttendanceEngine::iso($r->starts_at),
                'endsAt' => AttendanceEngine::iso($r->ends_at),
                'location' => $r->location,
                'purpose' => $r->purpose,
                'notes' => $r->notes,
                'hasEvidence' => $r->evidence_path !== null,
                'status' => $r->status,
                'statusLabel' => self::STATUS_LABELS[$r->status] ?? $r->status,
                'decisionNote' => $r->decision_note,
                'decidedBy' => $r->decided_by_name,
                'active' => $r->status === 'approved' && CarbonImmutable::parse($r->starts_at)->isPast() && CarbonImmutable::parse($r->ends_at)->isFuture(),
            ])->all();
    }

    /**
     * Pengajuan yang menunggu keputusan di satu outlet (kotak Persetujuan).
     *
     * @param  array{id: string}  $outlet
     */
    public function pending(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        if (! $actor->can('approval.view', $scope)) {
            throw AccessException::forbidden();
        }
        $mayDecide = $actor->can('attendance.approve_outside_duty', $scope);

        return DB::table('approvals')
            ->join('outside_duty_requests', 'outside_duty_requests.id', '=', 'approvals.reference_id')
            ->join('employees', 'employees.id', '=', 'outside_duty_requests.employee_id')
            ->where('approvals.company_id', $actor->company_id)
            ->where('approvals.outlet_id', $outlet['id'])
            ->where('approvals.type', 'outside_duty')
            ->where('approvals.status', 'pending')
            ->orderBy('outside_duty_requests.starts_at')
            ->limit(100)
            ->get([
                'approvals.id', 'approvals.requested_by', 'approvals.created_at', 'outside_duty_requests.id as request_id',
                'outside_duty_requests.duty_date', 'outside_duty_requests.starts_at', 'outside_duty_requests.ends_at',
                'outside_duty_requests.location', 'outside_duty_requests.purpose', 'outside_duty_requests.notes', 'outside_duty_requests.evidence_path',
                'employees.full_name', 'employees.employee_code',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'requestId' => $r->request_id,
                'employeeName' => $r->full_name,
                'employeeCode' => $r->employee_code,
                'dutyDate' => $r->duty_date,
                'startsAt' => AttendanceEngine::iso($r->starts_at),
                'endsAt' => AttendanceEngine::iso($r->ends_at),
                'location' => $r->location,
                'purpose' => $r->purpose,
                'notes' => $r->notes,
                'evidenceUrl' => $r->evidence_path === null ? null : "/admin/outside-duty/{$r->request_id}/evidence",
                'createdAt' => AttendanceEngine::iso($r->created_at),
                'canDecide' => $mayDecide && $r->requested_by !== $actor->id,
            ])->all();
    }

    /** Tugas luar disetujui yang bersinggungan dengan satu tanggal operasional (papan kehadiran). */
    public function forDate(User $actor, array $outlet, string $date): array
    {
        if (! $actor->can('attendance.view', AccessScope::outlet($outlet['id']))) {
            return [];
        }

        return DB::table('outside_duty_requests')
            ->join('approvals', 'approvals.id', '=', 'outside_duty_requests.approval_id')
            ->join('employees', 'employees.id', '=', 'outside_duty_requests.employee_id')
            ->where('outside_duty_requests.company_id', $actor->company_id)
            ->where('outside_duty_requests.outlet_id', $outlet['id'])
            ->where('outside_duty_requests.duty_date', $date)
            ->where('approvals.status', 'approved')
            ->orderBy('outside_duty_requests.starts_at')
            ->get(['outside_duty_requests.id', 'outside_duty_requests.starts_at', 'outside_duty_requests.ends_at', 'outside_duty_requests.location', 'outside_duty_requests.purpose', 'employees.full_name'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'employeeName' => $r->full_name,
                'startsAt' => AttendanceEngine::iso($r->starts_at),
                'endsAt' => AttendanceEngine::iso($r->ends_at),
                'location' => $r->location,
                'purpose' => $r->purpose,
            ])->all();
    }

    /** Manager/Owner memutuskan; pemohon tidak bisa memutuskan pengajuannya sendiri. */
    public function decide(User $actor, array $raw, AuditContext $context): array
    {
        $approvalId = CatalogInput::uuid($raw['approvalId'] ?? null);
        $approve = $raw['approve'] ?? null;
        if (! is_bool($approve)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $note = is_string($raw['note'] ?? null) && trim($raw['note']) !== '' ? mb_substr(trim($raw['note']), 0, 300) : null;
        $approval = DB::table('approvals')->where('id', $approvalId)->where('company_id', $actor->company_id)->where('type', 'outside_duty')->first()
            ?? throw AccessException::notFound();
        if (! $actor->can('attendance.approve_outside_duty', AccessScope::outlet($approval->outlet_id))) {
            throw AccessException::forbidden();
        }
        if ($approval->requested_by === $actor->id) {
            throw AccessException::forbidden('Anda tidak bisa memutuskan pengajuan Anda sendiri.');
        }

        return DB::transaction(function () use ($actor, $approval, $approve, $note, $context) {
            $decided = DB::selectOne(
                "update approvals set status = ?::approval_status, decided_by = ?, decision_note = ?, decided_at = clock_timestamp(), updated_at = now()
                 where id = ? and status = 'pending' returning id",
                [$approve ? 'approved' : 'rejected', $actor->id, $note, $approval->id],
            ) ?? throw new AccessException('conflict', 'Pengajuan ini sudah diputuskan.');
            $this->audit->record([
                'company_id' => $approval->company_id,
                'brand_id' => $approval->brand_id,
                'outlet_id' => $approval->outlet_id,
                'actor_id' => $actor->id,
                'action' => $approve ? 'outside_duty.approved' : 'outside_duty.rejected',
                'entity_type' => 'outside_duty',
                'entity_id' => $approval->reference_id,
                'after' => ['approvalId' => $decided->id, 'requestedBy' => $approval->requested_by],
                'reason' => $note,
            ], $context);

            return ['approved' => $approve];
        });
    }

    /**
     * Foto bukti untuk pemohon sendiri atau peninjau outlet (approval.view / attendance.view).
     *
     * @return array{bytes: string, contentType: string}
     */
    public function evidence(User $actor, mixed $requestId): array
    {
        $id = CatalogInput::uuid($requestId);
        $row = DB::table('outside_duty_requests')
            ->join('employees', 'employees.id', '=', 'outside_duty_requests.employee_id')
            ->where('outside_duty_requests.id', $id)
            ->where('outside_duty_requests.company_id', $actor->company_id)
            ->first(['outside_duty_requests.outlet_id', 'outside_duty_requests.evidence_path', 'employees.user_id']);
        if ($row === null || $row->evidence_path === null) {
            throw AccessException::notFound();
        }
        $scope = AccessScope::outlet($row->outlet_id);
        if ($row->user_id !== $actor->id && ! $actor->can('approval.view', $scope) && ! $actor->can('attendance.view', $scope)) {
            throw AccessException::notFound();
        }

        $bytes = $this->photos->download($row->evidence_path) ?? throw AccessException::notFound();

        return ['bytes' => $bytes, 'contentType' => AttendancePhotos::contentType($row->evidence_path)];
    }
}
