<?php

namespace App\Services\Attendance;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Support\Access\AccessScope;
use App\Support\Spreadsheet\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sisi admin absensi: papan kehadiran harian, rekap periode + ekspor, foto berizin, dan
 * pengaturan lokasi absen. Semua query dibatasi company actor dan outlet yang diizinkan.
 */
final class AttendanceAdmin
{
    public function __construct(
        private AccessLoader $access,
        private AuditLogger $audit,
        private AttendancePhotoStorage $photos,
        private AttendanceEngine $engine,
    ) {}

    /** Outlet yang boleh dilihat dengan izin tertentu. */
    public function outlets(User $actor, string $permission): array
    {
        return array_values(array_filter(
            $this->access->accessibleOutlets($actor),
            fn (array $o) => $actor->can($permission, AccessScope::outlet($o['id'])),
        ));
    }

    /**
     * Kehadiran satu outlet pada satu tanggal: semua karyawan aktif + sesi absennya.
     *
     * @param  array{id: string, timezone: string}  $outlet
     */
    public function board(User $actor, array $outlet, ?string $date): array
    {
        $this->assertAt($actor, 'attendance.view', $outlet['id']);
        $today = CarbonImmutable::now($outlet['timezone'])->toDateString();
        $date ??= $today;
        $employees = DB::table('employees')
            ->where('company_id', $actor->company_id)
            ->where('outlet_id', $outlet['id'])
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'employee_code', 'full_name', 'position']);
        $sessions = DB::table('attendances')
            ->where('company_id', $actor->company_id)
            ->where('outlet_id', $outlet['id'])
            ->where('business_date', $date)
            ->orderBy('check_in_at')
            ->get(['id', 'employee_id', 'check_in_at', 'check_out_at', 'working_minutes', 'check_in_method', 'check_out_method',
                'check_in_distance_meter', 'check_out_distance_meter', 'check_in_accuracy_meter', 'check_in_photo_path', 'check_out_photo_path'])
            ->groupBy('employee_id');

        $rows = $employees->map(function ($e) use ($sessions) {
            $mine = ($sessions[$e->id] ?? collect())->values();
            $open = $mine->first(fn ($s) => $s->check_out_at === null);

            return [
                'id' => $e->id,
                'code' => $e->employee_code,
                'fullName' => $e->full_name,
                'position' => $e->position,
                'status' => $open !== null ? 'working' : ($mine->isEmpty() ? 'absent' : 'done'),
                'minutes' => (int) $mine->sum('working_minutes'),
                'sessions' => $mine->map(fn ($s) => [
                    'id' => $s->id,
                    'checkInAt' => AttendanceEngine::iso($s->check_in_at),
                    'checkOutAt' => AttendanceEngine::iso($s->check_out_at),
                    'workingMinutes' => $s->working_minutes === null ? null : (int) $s->working_minutes,
                    'manual' => $s->check_in_method === 'manual_correction' || $s->check_out_method === 'manual_correction',
                    'checkInDistance' => $s->check_in_distance_meter === null ? null : (int) round((float) $s->check_in_distance_meter),
                    'checkOutDistance' => $s->check_out_distance_meter === null ? null : (int) round((float) $s->check_out_distance_meter),
                    'hasCheckInPhoto' => $s->check_in_photo_path !== null,
                    'hasCheckOutPhoto' => $s->check_out_photo_path !== null,
                ])->all(),
            ];
        })->all();

        return [
            'date' => $date,
            'isToday' => $date === $today,
            'rows' => $rows,
            'counts' => [
                'working' => count(array_filter($rows, fn ($r) => $r['status'] === 'working')),
                'done' => count(array_filter($rows, fn ($r) => $r['status'] === 'done')),
                'absent' => count(array_filter($rows, fn ($r) => $r['status'] === 'absent')),
            ],
        ];
    }

    /**
     * Rekap per karyawan untuk rentang tanggal (maks 62 hari).
     *
     * @param  array{id: string, timezone: string}  $outlet
     */
    public function report(User $actor, array $outlet, string $from, string $to): array
    {
        $this->assertAt($actor, 'report.attendance', $outlet['id']);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 62) {
            throw new AccessException('invalid', 'Rentang rekap maksimal 62 hari.');
        }
        $stats = DB::table('attendances')
            ->where('company_id', $actor->company_id)
            ->where('outlet_id', $outlet['id'])
            ->whereBetween('business_date', [$from, $to])
            ->groupBy('employee_id')
            ->selectRaw("employee_id, count(distinct business_date) as days, count(*) as sessions, coalesce(sum(working_minutes), 0) as minutes,
                count(*) filter (where check_in_method = 'manual_correction' or check_out_method = 'manual_correction') as manual,
                count(*) filter (where check_out_at is null) as incomplete")
            ->get()
            ->keyBy('employee_id');
        $employees = DB::table('employees')
            ->where('company_id', $actor->company_id)
            ->where('outlet_id', $outlet['id'])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $stats->keys()->all()))
            ->orderBy('full_name')
            ->get(['id', 'employee_code', 'full_name', 'position', 'is_active']);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $employees->map(function ($e) use ($stats) {
                $s = $stats[$e->id] ?? null;

                return [
                    'id' => $e->id,
                    'code' => $e->employee_code,
                    'fullName' => $e->full_name,
                    'position' => $e->position,
                    'isActive' => (bool) $e->is_active,
                    'days' => (int) ($s->days ?? 0),
                    'sessions' => (int) ($s->sessions ?? 0),
                    'minutes' => (int) ($s->minutes ?? 0),
                    'manual' => (int) ($s->manual ?? 0),
                    'incomplete' => (int) ($s->incomplete ?? 0),
                ];
            })->all(),
        ];
    }

    /** @return array{filename: string, bytes: string} */
    public function exportReport(User $actor, array $outlet, string $from, string $to): array
    {
        $report = $this->report($actor, $outlet, $from, $to);
        $rows = array_map(fn ($r) => [
            $r['code'], $r['fullName'], $r['position'], $r['days'], $r['sessions'],
            sprintf('%d:%02d', intdiv($r['minutes'], 60), $r['minutes'] % 60), $r['minutes'], $r['manual'], $r['incomplete'],
            $r['isActive'] ? 'Aktif' : 'Nonaktif',
        ], $report['rows']);
        $header = ['Kode', 'Nama', 'Jabatan', 'Hari hadir', 'Sesi', 'Jam kerja (j:m)', 'Jam kerja (menit)', 'Koreksi manual', 'Belum absen pulang', 'Status karyawan'];
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($outlet['name'] ?? 'outlet')), '-') ?: 'outlet';

        return [
            'filename' => "absensi-{$slug}-{$report['from']}-sd-{$report['to']}.xlsx",
            'bytes' => XlsxWriter::build('Rekap absensi', $header, $rows, [12, 28, 18, 12, 8, 16, 18, 16, 18, 16]),
        ];
    }

    /**
     * Foto absen untuk ditampilkan lewat server (bukan URL bucket).
     *
     * @return array{bytes: string, contentType: string}|null null = foto sudah dihapus retensi
     */
    public function photo(User $actor, string $attendanceId, string $side): ?array
    {
        $row = DB::table('attendances')->where('id', $attendanceId)->where('company_id', $actor->company_id)
            ->first(['outlet_id', 'check_in_photo_path', 'check_out_photo_path']) ?? throw AccessException::notFound();
        $this->assertAt($actor, 'attendance.view', $row->outlet_id);
        $path = $side === 'out' ? $row->check_out_photo_path : $row->check_in_photo_path;
        if ($path === null) {
            return null;
        }
        $bytes = $this->photos->download($path);

        return $bytes === null ? null : ['bytes' => $bytes, 'contentType' => AttendancePhotos::contentType($path)];
    }

    // --- Pengaturan ---------------------------------------------------------------------

    /** Lokasi absen per outlet + batas akurasi company. */
    public function settings(User $actor): array
    {
        $outlets = array_map(function (array $o) {
            $row = DB::table('outlets')->where('id', $o['id'])->first(['latitude', 'longitude', 'attendance_radius_meter']);

            return [
                'id' => $o['id'],
                'name' => $o['name'],
                'latitude' => $row->latitude === null ? null : (float) $row->latitude,
                'longitude' => $row->longitude === null ? null : (float) $row->longitude,
                // Lokasi belum pernah diatur → radius awal keputusan owner (30 m), bukan default DB 5 m.
                'radius' => $row->latitude === null ? AttendanceEngine::DEFAULT_RADIUS : (int) $row->attendance_radius_meter,
                'configured' => $row->latitude !== null && $row->longitude !== null,
            ];
        }, $this->outlets($actor, 'settings.manage'));
        if ($outlets === []) {
            throw AccessException::forbidden();
        }

        return [
            'outlets' => $outlets,
            'maxAccuracy' => $this->engine->settings($actor->company_id)['maxAccuracy'],
            'canEditCompany' => $actor->can('settings.manage', AccessScope::company()),
        ];
    }

    public function updateGeofence(User $actor, string $outletId, array $raw, AuditContext $context): void
    {
        $input = AttendanceInput::geofence($raw);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)
            ->first(['id', 'brand_id', 'latitude', 'longitude', 'attendance_radius_meter']) ?? throw AccessException::notFound();
        $this->assertAt($actor, 'settings.manage', $outlet->id);
        DB::transaction(function () use ($actor, $outlet, $input, $context) {
            DB::table('outlets')->where('id', $outlet->id)->update([
                'latitude' => $input['latitude'],
                'longitude' => $input['longitude'],
                'attendance_radius_meter' => $input['radius'],
                'updated_at' => DB::raw('now()'),
            ]);
            $this->audit->record([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'actor_id' => $actor->id,
                'action' => 'attendance.geofence_changed',
                'entity_type' => 'outlet',
                'entity_id' => $outlet->id,
                'before' => ['latitude' => $outlet->latitude === null ? null : (float) $outlet->latitude, 'longitude' => $outlet->longitude === null ? null : (float) $outlet->longitude, 'radiusMeter' => (int) $outlet->attendance_radius_meter],
                'after' => ['latitude' => $input['latitude'], 'longitude' => $input['longitude'], 'radiusMeter' => $input['radius']],
            ], $context);
        });
    }

    /** Batas akurasi GPS berlaku untuk seluruh company: butuh settings.manage company-wide. */
    public function updateAccuracy(User $actor, mixed $value, AuditContext $context): void
    {
        $accuracy = AttendanceInput::maxAccuracy($value);
        if (! $actor->can('settings.manage', AccessScope::company())) {
            throw AccessException::forbidden();
        }
        DB::transaction(function () use ($actor, $accuracy, $context) {
            $before = $this->engine->settings($actor->company_id)['maxAccuracy'];
            DB::statement(
                'insert into attendance_settings (company_id, max_gps_accuracy_meter, policy_version, updated_by) values (?, ?, ?, ?)
                 on conflict (company_id) do update set max_gps_accuracy_meter = excluded.max_gps_accuracy_meter, updated_by = excluded.updated_by, updated_at = now()',
                [$actor->company_id, $accuracy, AttendanceEngine::POLICY_VERSION, $actor->id],
            );
            $this->audit->record([
                'company_id' => $actor->company_id,
                'actor_id' => $actor->id,
                'action' => 'attendance.settings_changed',
                'entity_type' => 'attendance_settings',
                'before' => ['maxGpsAccuracyMeter' => $before],
                'after' => ['maxGpsAccuracyMeter' => $accuracy],
            ], $context);
        });
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
