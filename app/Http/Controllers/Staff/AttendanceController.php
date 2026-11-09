<?php

namespace App\Http\Controllers\Staff;

use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Absen masuk/pulang dari HP staf: GPS + foto selfie (M7-lite). */
class AttendanceController
{
    public function __construct(private AttendanceEngine $engine) {}

    public function index(Request $request): Response
    {
        return Inertia::render('staff/attendance', $this->engine->state($request->user()));
    }

    /** Pratinjau jarak & akurasi sebelum mengambil foto; tidak mencatat apa pun. */
    public function location(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'result' => $this->engine->preview($request->user(), $request->only(['latitude', 'longitude', 'accuracy', 'ageMs']))])
            ->header('Cache-Control', 'no-store');
    }

    public function checkIn(Request $request): RedirectResponse
    {
        $result = $this->engine->checkIn($request->user(), $this->position($request), $request->file('photo'), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Absen masuk tercatat pukul '.$this->time($request, $result['checkInAt']).'. Selamat bekerja!');

        return back();
    }

    public function checkOut(Request $request): RedirectResponse
    {
        $result = $this->engine->checkOut($request->user(), $this->position($request), $request->file('photo'), AuditContext::fromRequest($request));
        $hours = intdiv($result['workingMinutes'], 60);
        $minutes = $result['workingMinutes'] % 60;
        Inertia::flash('success', 'Absen pulang tercatat pukul '.$this->time($request, $result['checkOutAt'])."; total kerja {$hours} jam {$minutes} menit. Terima kasih!");

        return back();
    }

    public function history(Request $request): Response
    {
        return Inertia::render('staff/attendance-history', $this->engine->history($request->user(), AttendanceInput::month($request->query('month'))));
    }

    public function correction(Request $request): RedirectResponse
    {
        $this->engine->requestCorrection($request->user(), $request->only(['kind', 'attendanceId', 'checkIn', 'checkOut', 'reason']), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Koreksi terkirim dan menunggu persetujuan Manager atau Owner.');

        return back();
    }

    /** @return array<string, mixed> */
    private function position(Request $request): array
    {
        return $request->only(['latitude', 'longitude', 'accuracy', 'ageMs']);
    }

    private function time(Request $request, ?string $iso): string
    {
        $timezone = $this->engine->self($request->user())->timezone ?? 'Asia/Jakarta';

        return $iso === null ? '-' : CarbonImmutable::parse($iso)->setTimezone($timezone)->format('H.i');
    }
}
