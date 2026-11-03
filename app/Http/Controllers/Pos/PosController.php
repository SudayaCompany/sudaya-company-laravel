<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\Storefront;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use App\Support\Access\AccessScope;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Terminal kasir. Harga di layar perkiraan; harga sah dihitung order engine. */
class PosController
{
    public function __construct(private OrderEngine $orders, private OrderWorkflow $workflow) {}

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
            'canConfirmCash' => $request->user()->can('payment.confirm_cash', AccessScope::outlet($outlet['id'])),
            'cashSessionOpen' => CashSessions::hasOpen($outlet['id']),
        ]);
    }

    /**
     * Membuat order POS, lalu (opsional) langsung membayar tunai. Bila pembayaran
     * gagal, order tetap tersimpan sebagai belum dibayar dan kasir diberi tahu.
     */
    public function store(Request $request): RedirectResponse
    {
        $outletId = $request->input('outletId');
        $context = AuditContext::fromRequest($request);
        $created = $this->orders->create('pos', ['id' => is_string($outletId) ? $outletId : ''], $request->user(), $request->input('checkout'), $context);

        $placed = [
            'orderId' => $created['orderId'],
            'reference' => $created['reference'],
            'total' => $created['total'],
            'replayed' => $created['replayed'],
            'orderNumber' => null,
            'change' => null,
            'warning' => null,
        ];
        $tendered = $request->input('tenderedAmount');
        if ($tendered !== null && $request->user()->can('payment.confirm_cash', AccessScope::outlet($outletId))) {
            if (is_numeric($tendered) && (int) $tendered < $created['total']) {
                // Harga berubah sejak ditampilkan: order disimpan, belum dibayar.
                $placed['warning'] = 'Total terbaru '.Money::rupiah($created['total']).' melebihi uang diterima. Pesanan disimpan sebagai belum dibayar.';
            } else {
                try {
                    $paid = $this->workflow->confirmCash($request->user(), ['orderId' => $created['orderId'], 'tenderedAmount' => $tendered], $context);
                    $placed['orderNumber'] = $paid['orderNumber'];
                    $placed['change'] = $paid['change'];
                } catch (AccessException $error) {
                    // Order sudah tersimpan; pembayaran gagal (mis. sesi kas belum dibuka).
                    $placed['warning'] = "Pesanan disimpan sebagai belum dibayar: {$error->getMessage()}";
                }
            }
        }
        Inertia::flash('placed', $placed);

        return back();
    }
}
