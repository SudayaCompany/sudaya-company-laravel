<?php

namespace App\Http\Controllers;

use App\Services\Catalog\Storefront;
use App\Services\Site\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman depan Sudaya Coffee (publik). Data menu dan konten situs (tentang, program, pelatihan,
 * sedekah, kontak) dimuat sebagai deferred prop: halaman tampil seketika, dan bila database tidak
 * terjangkau halaman tetap tampil.
 */
class HomeController
{
    private const FEATURED = 6;

    public function __invoke(Request $request, Storefront $storefront): Response
    {
        $outlet = $request->query('outlet');
        $code = is_string($outlet) ? strtoupper($outlet) : null;

        return Inertia::render('home', [
            'cafe' => Inertia::defer(fn () => $this->cafe($storefront, $code), rescue: true),
            // Database tidak terjangkau: konten bawaan tetap tampil (tanpa kontak).
            'site' => Inertia::defer(fn () => SiteContent::forPublic(rescue(fn () => $this->brandId($storefront, $code), null, false)), rescue: true),
        ]);
    }

    /** Brand outlet yang dibuka (atau outlet default); tanpa outlet aktif, brand pertama. */
    private function brandId(Storefront $storefront, ?string $code): ?string
    {
        $code ??= $storefront->defaultOutletCode();
        $menu = $code === null ? null : $storefront->cached($code);

        return $menu['brand']['id'] ?? DB::table('brands')->orderBy('created_at')->value('id');
    }

    /** Data asli dari menu outlet `?outlet=`, atau outlet aktif tunggal (sama dengan QR tanpa ?outlet=). */
    private function cafe(Storefront $storefront, ?string $code): ?array
    {
        $code ??= $storefront->defaultOutletCode();
        $menu = $code === null ? null : $storefront->cached($code);
        if ($menu === null) {
            return null;
        }
        $categories = collect($menu['categories'])->keyBy('id');
        // Produk tersedia, yang berfoto lebih dulu; urutan menu dipertahankan.
        $featured = collect($menu['products'])
            ->filter(fn ($p) => $p['isAvailable'])
            ->sortBy(fn ($p) => $p['imageUrl'] === null ? 1 : 0)
            ->take(self::FEATURED)
            ->map(fn ($p) => [
                'id' => $p['id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'price' => $p['price'],
                'imageUrl' => $p['imageUrl'],
                'category' => $categories[$p['categoryId']]['name'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'outlet' => [
                'code' => $menu['outlet']['code'],
                'name' => $menu['outlet']['name'],
                'address' => DB::table('outlets')->where('code', $menu['outlet']['code'])->value('address'),
            ],
            'categories' => $categories->pluck('name')->values()->all(),
            'featured' => $featured,
            'productCount' => count($menu['products']),
        ];
    }
}
