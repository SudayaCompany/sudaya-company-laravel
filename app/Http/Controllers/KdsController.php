<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Pos\PosOutlet;
use App\Services\Orders\OrderEngine;
use App\Support\Access\AccessScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pesanan masuk (perangkat kasir, keputusan owner 12 November 2026): antrean pesanan lunas atau
 * bayar-saat-diantar (FIFO) + pesanan pelanggan yang menunggu dibayar di kasir.
 */
class KdsController
{
    public function __invoke(Request $request, OrderEngine $orders): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($orders, $request, 'kds.view');
        $scope = AccessScope::outlet($outlet['id']);

        return Inertia::render('kds/index', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'orders' => $orders->kdsBoard($request->user(), $outlet),
            'awaiting' => $orders->awaitingPayment($request->user(), $outlet),
            'canUpdate' => $request->user()->can('order.update_status', $scope),
            'canOverride' => $request->user()->can('kds.manage', $scope),
            // Bayar saat diantar: kasir menerima uang di meja lalu menandai lunas.
            'canTakePayment' => $request->user()->can('payment.confirm_cash', $scope),
            'topic' => OrderEngine::outletTopic($outlet['id']),
        ]);
    }
}
