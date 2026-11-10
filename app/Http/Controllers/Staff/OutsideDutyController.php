<?php

namespace App\Http\Controllers\Staff;

use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\OutsideDuty;
use App\Services\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Staf mengajukan dan memantau tugas luar dari HP (PRD §15). */
class OutsideDutyController
{
    public function __construct(private OutsideDuty $duties, private AttendanceEngine $engine) {}

    public function index(Request $request): Response
    {
        $employee = $this->engine->self($request->user());
        $timezone = $employee->timezone ?? 'Asia/Jakarta';
        $today = CarbonImmutable::now($timezone);

        return Inertia::render('staff/outside-duty', [
            'employee' => $employee === null ? null : [
                'fullName' => $employee->full_name,
                'outletName' => $employee->outlet_name,
                'timezone' => $timezone,
                'isActive' => (bool) $employee->is_active && (bool) $employee->outlet_active,
            ],
            'today' => $today->toDateString(),
            'maxDate' => $today->addDays(OutsideDuty::MAX_DAYS_AHEAD)->toDateString(),
            'requests' => $this->duties->mine($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->duties->request(
            $request->user(),
            $request->only(['dutyDate', 'startTime', 'endTime', 'location', 'purpose', 'notes']),
            $request->file('photo'),
            AuditContext::fromRequest($request),
        );
        Inertia::flash('success', 'Pengajuan tugas luar terkirim dan menunggu persetujuan Manager atau Owner.');

        return back();
    }

    public function cancel(Request $request, string $duty): RedirectResponse
    {
        $this->duties->cancel($request->user(), $duty, AuditContext::fromRequest($request));
        Inertia::flash('success', 'Pengajuan tugas luar dibatalkan.');

        return back();
    }
}
