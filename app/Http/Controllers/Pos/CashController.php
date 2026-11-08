<?php

namespace App\Http\Controllers\Pos;

use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Orders\OrderEngine;
use App\Support\Access\AccessScope;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sesi kas outlet: buka, kas masuk/keluar, tutup dengan hitung uang fisik. */
class CashController
{
    /** Area tampilan: 'pos' (layout kasir) atau 'admin' (hanya memantau, tanpa aksi laci). */
    protected const AREA = 'pos';

    public function __construct(private OrderEngine $orders, private CashSessions $cash) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'cash.view');
        $scope = AccessScope::outlet($outlet['id']);
        $cashier = static::AREA === 'pos';

        return Inertia::render('pos/cash', [
            'area' => static::AREA,
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'canOpen' => $cashier && $request->user()->can('cash.open', $scope),
            'canClose' => $cashier && $request->user()->can('cash.close', $scope),
            ...$this->cash->overview($request->user(), $outlet),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $this->cash->open($request->user(), $request->only(['outletId', 'openingAmount']), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Sesi kas dibuka.');

        return back();
    }

    public function movement(Request $request): RedirectResponse
    {
        $this->cash->recordMovement($request->user(), $request->only(['outletId', 'type', 'amount', 'reason']), AuditContext::fromRequest($request));
        Inertia::flash('success', $request->input('type') === 'cash_in' ? 'Kas masuk dicatat.' : 'Kas keluar dicatat.');

        return back();
    }

    public function close(Request $request): RedirectResponse
    {
        $result = $this->cash->close($request->user(), $request->only(['sessionId', 'actualAmount', 'reason']), AuditContext::fromRequest($request));
        Inertia::flash('success', $result['variance'] === 0
            ? 'Sesi kas ditutup. Uang cocok.'
            : 'Sesi kas ditutup dengan selisih '.Money::rupiah($result['variance']).'.');

        return back();
    }
}
