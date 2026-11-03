<?php

namespace App\Http\Controllers\Pos;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\Storefront;
use App\Services\Orders\OrderEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Terminal kasir. Harga di layar perkiraan; harga sah dihitung order engine. */
class PosController
{
    public function __construct(private OrderEngine $orders) {}

    public function index(Request $request, Storefront $storefront): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'order.create');
        // Data langsung dari DB (tanpa cache) agar status sold out selalu terbaru.
        $menu = $storefront->load($outlet['code']);

        return Inertia::render('pos/index', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'categories' => $menu['categories'] ?? [],
            'products' => $menu['products'] ?? [],
        ]);
    }

    /** Membuat order POS. Pembayaran dikonfirmasi terpisah (modul pembayaran, M4). */
    public function store(Request $request): RedirectResponse
    {
        $outletId = $request->input('outletId');
        $created = $this->orders->create(
            'pos',
            ['id' => is_string($outletId) ? $outletId : ''],
            $request->user(),
            $request->input('checkout'),
            AuditContext::fromRequest($request),
        );
        Inertia::flash('placed', [
            'orderId' => $created['orderId'],
            'reference' => $created['reference'],
            'total' => $created['total'],
            'replayed' => $created['replayed'],
        ]);

        return back();
    }
}
