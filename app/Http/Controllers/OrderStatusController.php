<?php

namespace App\Http\Controllers;

use App\Services\Orders\GuestTokens;
use App\Services\Orders\OrderEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Status pesanan guest. Token akses ada di cookie HttpOnly per order, jadi status
 * hanya bisa dibuka dari perangkat dan browser yang dipakai saat memesan.
 */
class OrderStatusController
{
    public function __invoke(Request $request, OrderEngine $orders, string $order): Response
    {
        $token = $request->cookie(GuestTokens::cookieName($order));
        $view = $orders->guestOrder($order, is_string($token) ? $token : null);

        return Inertia::render('order/status', ['order' => $view])
            ->toResponse($request)
            // Detail pesanan tidak boleh bocor lewat referrer ke pihak ketiga.
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
