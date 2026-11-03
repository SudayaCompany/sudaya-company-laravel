<?php

namespace App\Http\Controllers;

use App\Services\Catalog\Storefront;
use App\Services\Security\ClientIp;
use App\Services\Security\RateLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Menu customer. Publik: guest boleh melihat menu tanpa login (PRD §32). */
class OrderController
{
    private const TABLE_PATTERN = '/^[A-Za-z0-9-]{1,10}$/';

    /** Hitung ulang keranjang per IP per menit (dipanggil tiap isi keranjang berubah). */
    private const QUOTE_LIMIT_PER_MINUTE = 300;

    public function show(Request $request, Storefront $storefront): Response
    {
        // QR meja: /order?outlet=SDY01&table=T03 (PRD §110).
        $table = is_string($request->query('table')) && preg_match(self::TABLE_PATTERN, $request->query('table')) === 1 ? $request->query('table') : null;
        $outletCode = is_string($request->query('outlet')) ? $request->query('outlet') : $storefront->defaultOutletCode();

        if ($outletCode === null) {
            return Inertia::render('order/index', ['notice' => 'scan']);
        }
        $menu = $storefront->cached($outletCode);
        if ($menu === null) {
            return Inertia::render('order/index', ['notice' => 'not_found']);
        }

        return Inertia::render('order/index', ['storefront' => $menu, 'table' => $table]);
    }

    /**
     * Harga keranjang dihitung di server dari data terbaru di DB (bukan cache),
     * supaya harga, sold out, dan stok selalu akurat.
     */
    public function quote(Request $request, Storefront $storefront, RateLimits $limits): JsonResponse
    {
        $input = Storefront::quoteInput($request->json()->all());
        if ($input === null) {
            return response()->json(['ok' => false, 'error' => 'Data keranjang tidak valid.']);
        }

        $ip = ClientIp::trusted($request);
        if ($ip !== null && ! $limits->consume([['key' => "quote:ip:{$ip}", 'limit' => self::QUOTE_LIMIT_PER_MINUTE, 'windowSeconds' => 60]])['ok']) {
            return response()->json(['ok' => false, 'error' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'], 429);
        }

        $menu = $storefront->load($input['outlet'], withStock: true);
        if ($menu === null) {
            return response()->json(['ok' => false, 'error' => 'Outlet tidak ditemukan.']);
        }

        return response()->json(Storefront::quote($menu, $input['lines']));
    }
}
