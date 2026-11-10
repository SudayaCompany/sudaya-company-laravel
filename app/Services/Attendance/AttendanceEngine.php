<?php

namespace App\Services\Attendance;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogInput;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Absensi harian GPS + foto selfie (M7-lite, keputusan owner 8–9 November: opsi A).
 * Diport dari referensi Next.js `lib/attendance/engine.ts`, dengan verifikasi wajah
 * diganti foto selfie di bucket privat. Absen yang gagal geofence tidak pernah
 * dilonggarkan diam-diam; staf mengajukan koreksi yang diputuskan Manager/Owner.
 */
final class AttendanceEngine
{
    /** Metode absen harian (enum `attendance_method`, migration 2026_11_09_000001). */
    public const METHOD = 'gps_selfie';

    /** Versi kebijakan absensi GPS + selfie yang dicatat di tiap absen. */
    public const POLICY_VERSION = '2026-11-09';

    /** Keputusan owner 9 November (G4): akurasi GPS maksimal 30 m bila belum diatur. */
    public const DEFAULT_MAX_ACCURACY = 30;

    /** Keputusan owner 9 November (G4): radius awal 30 m saat lokasi outlet pertama kali diatur. */
    public const DEFAULT_RADIUS = 30;

    private const OPEN_MESSAGE = 'Anda masih tercatat masuk. Absen pulang dulu sebelum absen masuk lagi.';

    public function __construct(private AuditLogger $audit, private AttendancePhotoStorage $photos) {}

    // --- Profil & status staf -----------------------------------------------------------

    /** @return array{maxAccuracy: int, policyVersion: string} */
    public function settings(string $companyId): array
    {
        $row = DB::table('attendance_settings')->where('company_id', $companyId)->first(['max_gps_accuracy_meter']);

        return [
            'maxAccuracy' => $row === null ? self::DEFAULT_MAX_ACCURACY : (int) $row->max_gps_accuracy_meter,
            'policyVersion' => self::POLICY_VERSION,
        ];
    }

    /** Data karyawan yang ditautkan ke akun login actor, beserta outlet-nya. */
    public function self(User $actor): ?object
    {
        return DB::table('employees')
            ->join('outlets', 'outlets.id', '=', 'employees.outlet_id')
            ->where('employees.user_id', $actor->id)
            ->where('employees.company_id', $actor->company_id)
            ->first([
                'employees.id', 'employees.company_id', 'employees.brand_id', 'employees.outlet_id', 'employees.employee_code',
                'employees.full_name', 'employees.position', 'employees.is_active',
                'outlets.name as outlet_name', 'outlets.is_active as outlet_active', 'outlets.timezone',
                'outlets.latitude', 'outlets.longitude', 'outlets.attendance_radius_meter as radius',
            ]);
    }

    /** Ringkasan untuk halaman Absen staf (tanpa data karyawan lain). */
    public function state(User $actor): array
    {
        $employee = $this->self($actor);
        $settings = $this->settings($actor->company_id);
        if ($employee === null) {
            return ['employee' => null, 'settings' => $settings];
        }
        $today = DB::selectOne('select (now() at time zone ?)::date::text as d', [$employee->timezone])->d;
        $open = $this->open($employee->id);
        $sessions = DB::table('attendances')
            ->where('employee_id', $employee->id)
            ->where('business_date', $today)
            ->orderBy('check_in_at')
            ->get(['id', 'check_in_at', 'check_out_at', 'working_minutes', 'check_in_method', 'check_out_method']);

        return [
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->employee_code,
                'fullName' => $employee->full_name,
                'position' => $employee->position,
                'outletName' => $employee->outlet_name,
                'timezone' => $employee->timezone,
                'isActive' => (bool) $employee->is_active && (bool) $employee->outlet_active,
                'geofenceReady' => $employee->latitude !== null && $employee->longitude !== null,
                'radius' => (int) $employee->radius,
            ],
            'settings' => $settings,
            'businessDate' => $today,
            'open' => $open === null ? null : [
                'id' => $open->id,
                'checkInAt' => self::iso($open->check_in_at),
                'businessDate' => $open->business_date,
                'fromEarlierDay' => $open->business_date !== $today,
            ],
            'today' => $sessions->map(fn ($s) => [
                'id' => $s->id,
                'checkInAt' => self::iso($s->check_in_at),
                'checkOutAt' => self::iso($s->check_out_at),
                'workingMinutes' => $s->working_minutes === null ? null : (int) $s->working_minutes,
                'manual' => $s->check_in_method === 'manual_correction' || $s->check_out_method === 'manual_correction',
            ])->all(),
            'outsideDuty' => ($duty = OutsideDuty::active($employee->id)) === null ? null : ['location' => $duty->location, 'endsAt' => self::iso($duty->ends_at)],
            'pendingCorrections' => DB::table('attendance_corrections')
                ->join('approvals', 'approvals.id', '=', 'attendance_corrections.approval_id')
                ->where('attendance_corrections.employee_id', $employee->id)
                ->where('approvals.status', 'pending')
                ->count(),
        ];
    }

    // --- Absen masuk / pulang -----------------------------------------------------------

    /** @return array{id: string, checkInAt: string|null, distance: float|null} */
    public function checkIn(User $actor, array $raw, mixed $photo, AuditContext $context): array
    {
        $employee = $this->requireSelf($actor, 'attendance.checkin');
        if ($this->open($employee->id) !== null) {
            throw new AccessException('conflict', self::OPEN_MESSAGE);
        }
        $position = AttendanceInput::position($raw);
        $settings = $this->settings($actor->company_id);
        $outlet = ['latitude' => self::coord($employee->latitude), 'longitude' => self::coord($employee->longitude), 'radius' => (int) $employee->radius];
        // Tugas luar disetujui & sedang berlangsung: radius outlet tidak berlaku (GPS tetap dicatat).
        $duty = OutsideDuty::active($employee->id);
        $decision = $this->checkLocation($actor, $employee, $position, $outlet, $settings, 'check_in', $context, $duty !== null);
        $image = AttendancePhotos::read($photo);
        $businessDate = DB::selectOne('select (now() at time zone ?)::date::text as d', [$employee->timezone])->d;
        $path = AttendancePhotos::path($employee->company_id, $employee->id, $businessDate, $image['ext']);
        $this->photos->upload($path, $image['bytes'], $image['contentType']);

        try {
            return DB::transaction(function () use ($actor, $employee, $position, $decision, $path, $settings, $context, $duty) {
                // Tanggal bisnis dan jam masuk memakai now() yang sama (awal transaksi).
                $row = DB::selectOne(
                    'insert into attendances (company_id, brand_id, outlet_id, employee_id, business_date, status, policy_version,
                        check_in_method, check_in_latitude, check_in_longitude, check_in_accuracy_meter, check_in_distance_meter,
                        check_in_photo_path, check_in_device, check_in_ip, outside_duty_request_id)
                     values (?, ?, ?, ?, (now() at time zone ?)::date, \'checked_in\', ?, ?::attendance_method, ?, ?, ?, ?, ?, ?, ?, ?)
                     returning id, check_in_at',
                    [
                        $employee->company_id, $employee->brand_id, $employee->outlet_id, $employee->id, $employee->timezone,
                        $settings['policyVersion'], self::METHOD, $position['latitude'], $position['longitude'], $position['accuracy'],
                        $decision['distance'], $path, $context->userAgent === null ? null : mb_substr($context->userAgent, 0, 512),
                        $context->ip === null ? null : substr($context->ip, 0, 64), $duty?->id,
                    ],
                );
                $this->audit->record([
                    ...$this->auditBase($employee, $actor),
                    'action' => 'attendance.check_in',
                    'entity_type' => 'attendance',
                    'entity_id' => $row->id,
                    'after' => ['method' => self::METHOD, 'distanceMeter' => self::round1($decision['distance']), 'accuracyMeter' => (int) round($position['accuracy']), 'outsideDutyRequestId' => $duty?->id],
                ], $context);

                return ['id' => $row->id, 'checkInAt' => self::iso($row->check_in_at), 'distance' => self::round1($decision['distance'])];
            });
        } catch (Throwable $error) {
            $this->discard($path);
            if ($error instanceof QueryException && $error->getCode() === '23505') {
                throw new AccessException('conflict', self::OPEN_MESSAGE);
            }
            throw $error;
        }
    }

    /** @return array{id: string, checkOutAt: string|null, workingMinutes: int} */
    public function checkOut(User $actor, array $raw, mixed $photo, AuditContext $context): array
    {
        $employee = $this->requireSelf($actor, 'attendance.checkout');
        $open = $this->open($employee->id) ?? throw new AccessException('conflict', 'Anda belum absen masuk.');
        $position = AttendanceInput::position($raw);
        $settings = $this->settings($actor->company_id);
        // Geofence mengikuti outlet tempat sesi dibuka.
        $sessionOutlet = DB::table('outlets')->where('id', $open->outlet_id)->first(['latitude', 'longitude', 'attendance_radius_meter']);
        $outlet = ['latitude' => self::coord($sessionOutlet->latitude), 'longitude' => self::coord($sessionOutlet->longitude), 'radius' => (int) $sessionOutlet->attendance_radius_meter];
        $outsideDuty = OutsideDuty::active($employee->id) !== null || $open->outside_duty_request_id !== null;
        $decision = $this->checkLocation($actor, $employee, $position, $outlet, $settings, 'check_out', $context, $outsideDuty);
        $image = AttendancePhotos::read($photo);
        $path = AttendancePhotos::path($employee->company_id, $employee->id, $open->business_date, $image['ext']);
        $this->photos->upload($path, $image['bytes'], $image['contentType']);

        try {
            return DB::transaction(function () use ($actor, $employee, $open, $position, $decision, $path, $context) {
                // Kondisional: dua absen pulang bersamaan → hanya satu yang mengubah baris.
                $row = DB::selectOne(
                    "update attendances set status = 'checked_out', check_out_at = now(), check_out_method = ?::attendance_method,
                        check_out_latitude = ?, check_out_longitude = ?, check_out_accuracy_meter = ?, check_out_distance_meter = ?,
                        check_out_photo_path = ?, check_out_device = ?, check_out_ip = ?,
                        working_minutes = floor(extract(epoch from (now() - check_in_at)) / 60)::int, updated_at = now()
                     where id = ? and check_out_at is null
                     returning id, check_out_at, working_minutes",
                    [
                        self::METHOD, $position['latitude'], $position['longitude'], $position['accuracy'], $decision['distance'], $path,
                        $context->userAgent === null ? null : mb_substr($context->userAgent, 0, 512),
                        $context->ip === null ? null : substr($context->ip, 0, 64), $open->id,
                    ],
                ) ?? throw new AccessException('conflict', 'Sesi ini sudah tercatat pulang.');
                $this->audit->record([
                    ...$this->auditBase($employee, $actor),
                    'action' => 'attendance.check_out',
                    'entity_type' => 'attendance',
                    'entity_id' => $row->id,
                    'after' => ['method' => self::METHOD, 'workingMinutes' => (int) $row->working_minutes, 'distanceMeter' => self::round1($decision['distance']), 'accuracyMeter' => (int) round($position['accuracy'])],
                ], $context);

                return ['id' => $row->id, 'checkOutAt' => self::iso($row->check_out_at), 'workingMinutes' => (int) $row->working_minutes];
            });
        } catch (Throwable $error) {
            $this->discard($path);
            throw $error;
        }
    }

    /**
     * Cek lokasi tanpa mencatat apa pun, untuk pratinjau di HP. Keputusan sah tetap saat kirim.
     *
     * @return array{ok: bool, reason: string|null, message: string|null, distance: float|null, accuracy: int, radius: int, maxAccuracy: int}
     */
    public function preview(User $actor, array $raw): array
    {
        $employee = $this->requireSelf($actor, 'attendance.checkin');
        $position = AttendanceInput::position($raw);
        $settings = $this->settings($actor->company_id);
        $open = $this->open($employee->id);
        $source = $open === null ? $employee : DB::table('outlets')->where('id', $open->outlet_id)->first(['latitude', 'longitude', 'attendance_radius_meter as radius']);
        $outsideDuty = OutsideDuty::active($employee->id) !== null || ($open?->outside_duty_request_id ?? null) !== null;
        $decision = Geofence::evaluate($position, ['latitude' => self::coord($source->latitude), 'longitude' => self::coord($source->longitude), 'radius' => (int) $source->radius], $settings['maxAccuracy'], $outsideDuty);

        return [...$decision, 'distance' => self::round1($decision['distance']), 'accuracy' => (int) round($position['accuracy']), 'radius' => (int) $source->radius, 'maxAccuracy' => $settings['maxAccuracy']];
    }

    // --- Koreksi absen ------------------------------------------------------------------

    /** Staf mengajukan koreksi (lupa absen / lupa pulang); berlaku setelah disetujui. */
    public function requestCorrection(User $actor, array $raw, AuditContext $context): array
    {
        $input = AttendanceInput::correction($raw);
        $employee = $this->requireSelf($actor, 'attendance.checkin');
        $tz = $employee->timezone;
        $checkOut = $this->localTime($input['checkOut'], $tz);
        $checkIn = null;
        $attendanceId = null;

        if ($input['kind'] === 'missing_check_out') {
            $session = DB::table('attendances')->where('id', $input['attendanceId'])->where('employee_id', $employee->id)->first(['id', 'check_in_at', 'check_out_at', 'business_date'])
                ?? throw AccessException::notFound();
            if ($session->check_out_at !== null) {
                throw new AccessException('conflict', 'Sesi ini sudah punya jam pulang.');
            }
            if ($checkOut['future']) {
                throw new AccessException('invalid', 'Jam pulang tidak boleh di masa depan.');
            }
            if (CarbonImmutable::parse($checkOut['at'])->lessThanOrEqualTo(CarbonImmutable::parse($session->check_in_at))) {
                throw new AccessException('invalid', 'Jam pulang harus setelah jam masuk.');
            }
            $businessDate = $session->business_date;
            $attendanceId = $session->id;
        } else {
            $checkIn = $this->localTime($input['checkIn'], $tz);
            if ($checkIn['future'] || $checkOut['future']) {
                throw new AccessException('invalid', 'Waktu absen tidak boleh di masa depan.');
            }
            $minutes = (CarbonImmutable::parse($checkOut['at'])->getTimestamp() - CarbonImmutable::parse($checkIn['at'])->getTimestamp()) / 60;
            if ($minutes <= 0) {
                throw new AccessException('invalid', 'Jam pulang harus setelah jam masuk.');
            }
            if ($minutes > 24 * 60) {
                throw new AccessException('invalid', 'Durasi satu sesi maksimal 24 jam.');
            }
            $businessDate = $checkIn['date'];
        }

        return DB::transaction(function () use ($actor, $employee, $input, $checkIn, $checkOut, $attendanceId, $businessDate, $context) {
            $pending = DB::table('attendance_corrections')
                ->join('approvals', 'approvals.id', '=', 'attendance_corrections.approval_id')
                ->where('attendance_corrections.employee_id', $employee->id)
                ->where('approvals.status', 'pending')
                ->when(
                    $attendanceId !== null,
                    fn ($q) => $q->where('attendance_corrections.attendance_id', $attendanceId),
                    fn ($q) => $q->where('attendance_corrections.kind', 'missing_attendance')->where('attendance_corrections.business_date', $businessDate),
                )
                ->exists();
            if ($pending) {
                throw new AccessException('conflict', 'Koreksi serupa masih menunggu persetujuan.');
            }
            $correctionId = DB::table('attendance_corrections')->insertGetId([
                'company_id' => $employee->company_id,
                'brand_id' => $employee->brand_id,
                'outlet_id' => $employee->outlet_id,
                'employee_id' => $employee->id,
                'kind' => $input['kind'],
                'business_date' => $businessDate,
                'attendance_id' => $attendanceId,
                'requested_check_in_at' => $checkIn['at'] ?? null,
                'requested_check_out_at' => $checkOut['at'],
                'reason' => $input['reason'],
                'created_by' => $actor->id,
            ]);
            $approvalId = DB::table('approvals')->insertGetId([
                'company_id' => $employee->company_id,
                'brand_id' => $employee->brand_id,
                'outlet_id' => $employee->outlet_id,
                'type' => 'attendance_correction',
                'reference_id' => $correctionId,
                'reason' => $input['reason'],
                'requested_by' => $actor->id,
            ]);
            DB::table('attendance_corrections')->where('id', $correctionId)->update(['approval_id' => $approvalId]);
            $this->audit->record([
                ...$this->auditBase($employee, $actor),
                'action' => 'attendance.correction_requested',
                'entity_type' => 'attendance_correction',
                'entity_id' => $correctionId,
                'after' => ['kind' => $input['kind'], 'businessDate' => $businessDate, 'checkInAt' => $checkIn['at'] ?? null, 'checkOutAt' => $checkOut['at'], 'attendanceId' => $attendanceId, 'approvalId' => $approvalId],
                'reason' => $input['reason'],
            ], $context);

            return ['correctionId' => $correctionId, 'approvalId' => $approvalId];
        });
    }

    /**
     * Koreksi yang menunggu keputusan di satu outlet (untuk kotak Persetujuan).
     *
     * @param  array{id: string, timezone: string}  $outlet
     */
    public function pendingCorrections(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        if (! $actor->can('approval.view', $scope)) {
            throw AccessException::forbidden();
        }
        $mayDecide = $actor->can('approval.approve', $scope) || $actor->can('approval.reject', $scope);

        return DB::table('approvals')
            ->join('attendance_corrections', 'attendance_corrections.id', '=', 'approvals.reference_id')
            ->join('employees', 'employees.id', '=', 'attendance_corrections.employee_id')
            ->leftJoin('attendances', 'attendances.id', '=', 'attendance_corrections.attendance_id')
            ->where('approvals.company_id', $actor->company_id)
            ->where('approvals.outlet_id', $outlet['id'])
            ->where('approvals.type', 'attendance_correction')
            ->where('approvals.status', 'pending')
            ->orderBy('approvals.created_at')
            ->limit(100)
            ->get([
                'approvals.id', 'approvals.reason', 'approvals.requested_by', 'approvals.created_at',
                'attendance_corrections.kind', 'attendance_corrections.business_date', 'attendance_corrections.requested_check_in_at',
                'attendance_corrections.requested_check_out_at', 'attendances.check_in_at as session_check_in_at',
                'employees.full_name', 'employees.employee_code',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'employeeName' => $r->full_name,
                'employeeCode' => $r->employee_code,
                'businessDate' => $r->business_date,
                'checkInAt' => self::iso($r->requested_check_in_at ?? $r->session_check_in_at),
                'checkOutAt' => self::iso($r->requested_check_out_at),
                'reason' => $r->reason,
                'createdAt' => self::iso($r->created_at),
                'canDecide' => $mayDecide && $r->requested_by !== $actor->id,
            ])->all();
    }

    /** Manager/Owner memutuskan koreksi. Disetujui → sesi bermetode `manual_correction`. */
    public function decideCorrection(User $actor, array $raw, AuditContext $context): array
    {
        $approvalId = CatalogInput::uuid($raw['approvalId'] ?? null);
        $approve = $raw['approve'] ?? null;
        if (! is_bool($approve)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $note = is_string($raw['note'] ?? null) && trim($raw['note']) !== '' ? mb_substr(trim($raw['note']), 0, 300) : null;
        $approval = DB::table('approvals')->where('id', $approvalId)->where('company_id', $actor->company_id)->where('type', 'attendance_correction')->first()
            ?? throw AccessException::notFound();
        if (! $actor->can($approve ? 'approval.approve' : 'approval.reject', AccessScope::outlet($approval->outlet_id))) {
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
            );
            if ($decided === null) {
                throw new AccessException('conflict', 'Pengajuan ini sudah diputuskan.');
            }
            $correction = DB::selectOne('select * from attendance_corrections where id = ? for update', [$approval->reference_id])
                ?? throw AccessException::notFound();
            $base = [
                'company_id' => $approval->company_id,
                'brand_id' => $approval->brand_id,
                'outlet_id' => $approval->outlet_id,
                'actor_id' => $actor->id,
                'entity_type' => 'attendance_correction',
                'entity_id' => $correction->id,
                'reason' => $note,
            ];
            if (! $approve) {
                $this->audit->record([...$base, 'action' => 'attendance.correction_rejected', 'after' => ['approvalId' => $approval->id]], $context);

                return ['approved' => false, 'attendanceId' => null];
            }

            if ($correction->kind === 'missing_check_out') {
                $row = DB::selectOne(
                    "update attendances set status = 'checked_out', check_out_at = ?::timestamptz, check_out_method = 'manual_correction',
                        working_minutes = floor(extract(epoch from (?::timestamptz - check_in_at)) / 60)::int, updated_at = now()
                     where id = ? and check_out_at is null and check_in_at < ?::timestamptz returning id",
                    [$correction->requested_check_out_at, $correction->requested_check_out_at, $correction->attendance_id, $correction->requested_check_out_at],
                ) ?? throw new AccessException('conflict', 'Sesi sudah ditutup atau jam pulang tidak valid.');
                $attendanceId = $row->id;
            } else {
                // Tidak boleh bertumpuk dengan sesi lain milik karyawan (sesi terbuka = sampai tak hingga).
                $overlap = DB::table('attendances')
                    ->where('employee_id', $correction->employee_id)
                    ->whereRaw('check_in_at < ?::timestamptz', [$correction->requested_check_out_at])
                    ->whereRaw("coalesce(check_out_at, 'infinity'::timestamptz) > ?::timestamptz", [$correction->requested_check_in_at])
                    ->exists();
                if ($overlap) {
                    throw new AccessException('conflict', 'Waktu koreksi bertumpuk dengan absen lain.');
                }
                $row = DB::selectOne(
                    "insert into attendances (company_id, brand_id, outlet_id, employee_id, business_date, status, policy_version,
                        check_in_at, check_in_method, check_out_at, check_out_method, working_minutes)
                     values (?, ?, ?, ?, ?, 'checked_out', ?, ?::timestamptz, 'manual_correction', ?::timestamptz, 'manual_correction',
                        floor(extract(epoch from (?::timestamptz - ?::timestamptz)) / 60)::int)
                     returning id",
                    [
                        $correction->company_id, $correction->brand_id, $correction->outlet_id, $correction->employee_id, $correction->business_date,
                        self::POLICY_VERSION, $correction->requested_check_in_at, $correction->requested_check_out_at,
                        $correction->requested_check_out_at, $correction->requested_check_in_at,
                    ],
                );
                $attendanceId = $row->id;
                DB::table('attendance_corrections')->where('id', $correction->id)->update(['attendance_id' => $attendanceId, 'updated_at' => DB::raw('now()')]);
            }
            $this->audit->record([
                ...$base,
                'action' => 'attendance.correction_approved',
                'after' => ['approvalId' => $approval->id, 'attendanceId' => $attendanceId, 'kind' => $correction->kind, 'method' => 'manual_correction'],
            ], $context);

            return ['approved' => true, 'attendanceId' => $attendanceId];
        });
    }

    // --- Riwayat staf -------------------------------------------------------------------

    /** Riwayat absen milik sendiri per bulan (default bulan berjalan, zona waktu outlet). */
    public function history(User $actor, ?string $month): array
    {
        $employee = $this->self($actor) ?? throw new AccessException('forbidden', 'Akun Anda belum terhubung ke data karyawan. Hubungi admin.');
        if (! $actor->can('attendance.checkin', AccessScope::outlet($employee->outlet_id))) {
            throw AccessException::forbidden();
        }
        $month ??= CarbonImmutable::now($employee->timezone)->format('Y-m');
        $from = "{$month}-01";
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        $rows = DB::table('attendances')
            ->where('employee_id', $employee->id)
            ->whereBetween('business_date', [$from, $to])
            ->orderByDesc('business_date')->orderByDesc('check_in_at')
            ->get(['id', 'business_date', 'check_in_at', 'check_out_at', 'working_minutes', 'check_in_method', 'check_out_method']);

        return [
            'month' => $month,
            'timezone' => $employee->timezone,
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'businessDate' => $r->business_date,
                'checkInAt' => self::iso($r->check_in_at),
                'checkOutAt' => self::iso($r->check_out_at),
                'workingMinutes' => $r->working_minutes === null ? null : (int) $r->working_minutes,
                'manual' => $r->check_in_method === 'manual_correction' || $r->check_out_method === 'manual_correction',
            ])->all(),
            'totals' => [
                'days' => $rows->pluck('business_date')->unique()->count(),
                'minutes' => (int) $rows->sum('working_minutes'),
            ],
        ];
    }

    // --- Bantuan ------------------------------------------------------------------------

    public function requireSelf(User $actor, string $permission): object
    {
        $employee = $this->self($actor) ?? throw new AccessException('forbidden', 'Akun Anda belum terhubung ke data karyawan. Hubungi admin.');
        if (! $actor->can($permission, AccessScope::outlet($employee->outlet_id))) {
            throw AccessException::forbidden();
        }
        if (! $employee->is_active) {
            throw new AccessException('forbidden', 'Status karyawan Anda nonaktif. Hubungi admin.');
        }
        if (! $employee->outlet_active) {
            throw new AccessException('forbidden', 'Outlet Anda sedang nonaktif.');
        }

        return $employee;
    }

    private function open(string $employeeId): ?object
    {
        return DB::table('attendances')->where('employee_id', $employeeId)->whereNull('check_out_at')
            ->first(['id', 'outlet_id', 'business_date', 'check_in_at', 'outside_duty_request_id']);
    }

    /** Geofence; penolakan dicatat di log aktivitas (tanpa foto). */
    private function checkLocation(User $actor, object $employee, array $position, array $outlet, array $settings, string $purpose, AuditContext $context, bool $outsideDuty): array
    {
        $decision = Geofence::evaluate($position, $outlet, $settings['maxAccuracy'], $outsideDuty);
        if (! $decision['ok']) {
            $this->audit->record([
                ...$this->auditBase($employee, $actor),
                'action' => 'attendance.location_rejected',
                'entity_type' => 'employee',
                'entity_id' => $employee->id,
                'after' => ['purpose' => $purpose, 'reason' => $decision['reason'], 'accuracyMeter' => (int) round($position['accuracy']), 'distanceMeter' => self::round1($decision['distance'])],
            ], $context);
            throw new AccessException('invalid', $decision['message']);
        }

        return $decision;
    }

    /** Waktu lokal outlet → timestamptz (ISO) + tanggal lokal + apakah di masa depan. */
    private function localTime(string $local, string $timezone): array
    {
        $row = DB::selectOne(
            'select (?::timestamp at time zone ?) as at, (?::timestamp)::date::text as d, (?::timestamp at time zone ?) > now() as future',
            [$local, $timezone, $local, $local, $timezone],
        );

        return ['at' => CarbonImmutable::parse($row->at)->toIso8601String(), 'date' => $row->d, 'future' => (bool) $row->future];
    }

    private function discard(string $path): void
    {
        try {
            $this->photos->remove([$path]);
        } catch (Throwable) {
            // Foto yatim dibersihkan oleh retensi; kegagalan hapus tidak menutupi error asli.
        }
    }

    private function auditBase(object $employee, User $actor): array
    {
        return ['company_id' => $employee->company_id, 'brand_id' => $employee->brand_id, 'outlet_id' => $employee->outlet_id, 'actor_id' => $actor->id];
    }

    private static function coord(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function round1(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }

    public static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->toIso8601String();
    }
}
