<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\AccessException;
use App\Services\Cash\CashSessions;
use App\Services\Orders\OrderEngine;
use App\Support\Access\AccessScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosOrderController
{
    private const VIEWS = ['pending' => 'pending_payment', 'active' => 'active', 'paid' => 'paid', 'today' => null];

    /** Area tampilan: 'pos' (layout kasir) atau 'admin' (sidebar admin, mis. untuk Owner). */
    protected const AREA = 'pos';

    public function __construct(private OrderEngine $orders) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'order.view');
        $view = array_key_exists((string) $request->query('view'), self::VIEWS) ? (string) $request->query('view') : 'pending';
        $q = is_string($request->query('q')) && trim($request->query('q')) !== '' ? mb_substr(trim($request->query('q')), 0, 60) : null;

        return Inertia::render('pos/orders/index', [
            'area' => static::AREA,
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'view' => $view,
            'q' => $q,
            'list' => $this->orders->list($request->user(), $outlet, ['status' => self::VIEWS[$view], 'q' => $q, 'page' => $request->query('page', 1)]),
            'topic' => OrderEngine::outletTopic($outlet['id']),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $detail = $this->load($request, $order);
        $user = $request->user();
        $scope = AccessScope::outlet($detail['outletId']);
        // Bayar, batal, dan refund adalah aksi kasir: hanya di area POS. Area admin hanya
        // memantau dan memutuskan persetujuan.
        $cashier = static::AREA === 'pos';

        return Inertia::render('pos/orders/show', [
            'area' => static::AREA,
            'order' => $detail,
            // Hanya untuk menampilkan tombol; setiap aksi diperiksa ulang di server.
            'flags' => [
                'canConfirmCash' => $cashier && $user->can('payment.confirm_cash', $scope),
                'canCancel' => $cashier && $user->can('order.void', $scope),
                'canDecide' => ($user->can('approval.approve', $scope) || $user->can('approval.reject', $scope))
                    && ($detail['pendingApproval']['requestedBy'] ?? null) !== $user->id,
                'canRefund' => $cashier && $user->can('payment.refund', $scope),
            ],
            'cashSessionOpen' => CashSessions::hasOpen($detail['outletId']),
            'topic' => OrderEngine::outletTopic($detail['outletId']),
        ]);
    }

    /** Struk 58–80 mm, dicetak lewat dialog print browser (PRD §46). */
    public function receipt(Request $request, string $order): Response
    {
        return Inertia::render('pos/orders/receipt', ['order' => $this->load($request, $order)]);
    }

    private function load(Request $request, string $order): array
    {
        try {
            return $this->orders->detail($request->user(), $order);
        } catch (AccessException) {
            // Order company lain, outlet tanpa izin, atau id tidak valid: 404 tanpa bocor info.
            abort(404);
        }
    }
}
