<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\AccessException;
use App\Services\Orders\OrderEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosOrderController
{
    private const VIEWS = ['pending' => 'pending_payment', 'active' => 'active', 'today' => null];

    public function __construct(private OrderEngine $orders) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'order.view');
        $view = array_key_exists((string) $request->query('view'), self::VIEWS) ? (string) $request->query('view') : 'pending';
        $q = is_string($request->query('q')) && trim($request->query('q')) !== '' ? mb_substr(trim($request->query('q')), 0, 60) : null;

        return Inertia::render('pos/orders/index', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'view' => $view,
            'q' => $q,
            'list' => $this->orders->list($request->user(), $outlet, ['status' => self::VIEWS[$view], 'q' => $q, 'page' => $request->query('page', 1)]),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        return Inertia::render('pos/orders/show', ['order' => $this->load($request, $order)]);
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
