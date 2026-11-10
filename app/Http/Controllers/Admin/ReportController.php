<?php

namespace App\Http\Controllers\Admin;

use App\Services\Auth\AccessLoader;
use App\Services\Reports\SalesReport;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Laporan penjualan + ekspor spreadsheet (report.sales). Default: hari ini (laporan harian Owner). */
class ReportController
{
    public function __construct(private SalesReport $reports, private AccessLoader $access) {}

    public function sales(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request);
        [$from, $to] = $this->range($request, $outlet['timezone']);

        return Inertia::render('admin/reports/sales', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'today' => CarbonImmutable::now($outlet['timezone'])->toDateString(),
            'report' => $this->reports->report($request->user(), $outlet, $from, $to),
        ]);
    }

    public function export(Request $request): SymfonyResponse
    {
        [, $outlet] = $this->outlet($request);
        [$from, $to] = $this->range($request, $outlet['timezone']);
        $file = $this->reports->export($request->user(), $outlet, $from, $to);

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    private function outlet(Request $request): array
    {
        $user = $request->user();
        $outlets = array_values(array_filter($this->access->accessibleOutlets($user), fn ($o) => $user->can('report.sales', AccessScope::outlet($o['id']))));
        abort_if($outlets === [], 403);
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $request->query('outlet')))[0] ?? $outlets[0];

        return [$outlets, $outlet];
    }

    private function range(Request $request, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $valid = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;

        $from = $valid($request->query('from')) ?? $today;
        $to = $valid($request->query('to')) ?? $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // Rentang terlalu panjang dipotong ke batas maksimal, bukan error di halaman GET.
        $earliest = CarbonImmutable::parse($to)->subDays(SalesReport::MAX_DAYS - 1)->toDateString();

        return [max($from, $earliest), $to];
    }
}
