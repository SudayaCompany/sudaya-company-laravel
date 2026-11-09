<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Attendance\AttendanceAdmin;
use App\Services\Attendance\AttendanceInput;
use App\Services\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Kehadiran harian, rekap + ekspor, foto absen berizin, dan pengaturan lokasi absen. */
class AttendanceController
{
    public function __construct(private AttendanceAdmin $attendance) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'attendance.view');

        return Inertia::render('admin/attendance/index', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'board' => $this->attendance->board($request->user(), $outlet, AttendanceInput::date($request->query('date'))),
        ]);
    }

    /** Foto selfie diteruskan dari bucket privat; tidak pernah di-cache browser/proxy. */
    public function photo(Request $request, string $attendance, string $side): SymfonyResponse
    {
        if (! in_array($side, ['in', 'out'], true) || ! preg_match('/^[0-9a-f-]{36}$/i', $attendance)) {
            abort(404);
        }
        try {
            $photo = $this->attendance->photo($request->user(), strtolower($attendance), $side);
        } catch (AccessException $error) {
            abort($error->reason === 'forbidden' ? 403 : 404);
        }
        if ($photo === null) {
            abort(404);
        }

        return response($photo['bytes'], 200, [
            'Content-Type' => $photo['contentType'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function reports(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'report.attendance');
        [$from, $to] = $this->range($request, $outlet['timezone']);

        return Inertia::render('admin/attendance/reports', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name']],
            'report' => $this->attendance->report($request->user(), $outlet, $from, $to),
        ]);
    }

    public function export(Request $request): SymfonyResponse
    {
        [, $outlet] = $this->outlet($request, 'report.attendance');
        [$from, $to] = $this->range($request, $outlet['timezone']);
        $file = $this->attendance->exportReport($request->user(), $outlet, $from, $to);

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function settings(Request $request): Response
    {
        return Inertia::render('admin/attendance/settings', $this->attendance->settings($request->user()));
    }

    public function updateGeofence(Request $request, string $outlet): RedirectResponse
    {
        $this->attendance->updateGeofence($request->user(), $outlet, $request->only(['latitude', 'longitude', 'radius']), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Lokasi absen outlet tersimpan.');

        return back();
    }

    public function updateAccuracy(Request $request): RedirectResponse
    {
        $this->attendance->updateAccuracy($request->user(), $request->input('maxAccuracy'), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Batas akurasi GPS tersimpan.');

        return back();
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    private function outlet(Request $request, string $permission): array
    {
        $outlets = $this->attendance->outlets($request->user(), $permission);
        abort_if($outlets === [], 403);
        $requested = $request->query('outlet');
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $requested))[0] ?? $outlets[0];

        return [$outlets, $outlet];
    }

    /** Default bulan berjalan (zona waktu outlet). */
    private function range(Request $request, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        return [
            AttendanceInput::date($request->query('from')) ?? $now->startOfMonth()->toDateString(),
            AttendanceInput::date($request->query('to')) ?? $now->toDateString(),
        ];
    }
}
