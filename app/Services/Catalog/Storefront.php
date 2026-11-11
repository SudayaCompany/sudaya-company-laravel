<?php

namespace App\Services\Catalog;

use App\Support\Catalog\Pricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Katalog customer per outlet, padanan lib/catalog/storefront.ts dan quote.ts.
 * Hanya data yang aman untuk publik yang dikembalikan.
 */
final class Storefront
{
    private const OUTLET_CODE = '/^[A-Za-z0-9_-]{1,32}$/';

    private const CACHE_SECONDS = 300;

    /**
     * Ketersediaan (PRD §21, §73), tiga hal terpisah:
     * - manual sold out: outlet_products.is_available = false (diatur admin);
     * - inventory unavailable: produk jadi yang terhubung ke item stok dengan saldo 0 atau item nonaktif;
     * - effective: tidak manual sold out dan tidak inventory unavailable.
     * Minuman racikan (tanpa link item atau BOM) hanya mengikuti status manual.
     */
    public static function availability(bool $manualAvailable, ?string $linkedItemId, ?string $linkedStock, ?bool $linkedActive): array
    {
        $manualSoldOut = ! $manualAvailable;
        $inventoryUnavailable = $linkedItemId !== null && (! $linkedActive || (float) ($linkedStock ?? 0) <= 0);

        return ['manualSoldOut' => $manualSoldOut, 'inventoryUnavailable' => $inventoryUnavailable, 'effective' => ! $manualSoldOut && ! $inventoryUnavailable];
    }

    /** Untuk halaman menu: di-cache singkat, dibuang setiap kali admin mengubah menu brand ini. */
    public function cached(string $outletCode): ?array
    {
        if (preg_match(self::OUTLET_CODE, $outletCode) !== 1) {
            return null;
        }
        $code = strtoupper($outletCode);
        $brandId = Cache::remember("storefront:outlet:{$code}:brand", self::CACHE_SECONDS, fn () => DB::table('outlets')->where('code', $code)->where('is_active', true)->value('brand_id') ?? '');
        if ($brandId === '') {
            return null;
        }
        $version = (int) Cache::get("storefront:brand:{$brandId}:version", 0);

        return Cache::remember("storefront:{$code}:v{$version}", self::CACHE_SECONDS, fn () => $this->load($code));
    }

    public function forgetBrand(string $brandId): void
    {
        Cache::forever("storefront:brand:{$brandId}:version", (int) Cache::get("storefront:brand:{$brandId}:version", 0) + 1);
    }

    /** Setelah nama atau status outlet berubah: buang juga cache brand per kode (outlet nonaktif tercache kosong). */
    public function forgetOutlet(string $outletCode, string $brandId): void
    {
        Cache::forget('storefront:outlet:'.strtoupper($outletCode).':brand');
        $this->forgetBrand($brandId);
    }

    /** Kode outlet default bila QR tidak menyertakan ?outlet= (hanya jika outlet aktif tunggal). */
    public function defaultOutletCode(): ?string
    {
        $codes = DB::table('outlets')->where('is_active', true)->limit(2)->pluck('code');

        return $codes->count() === 1 ? $codes[0] : null;
    }

    /** Data terbaru dari DB, tanpa cache. `withStock` hanya untuk hitungan server. */
    public function load(string $outletCode, bool $withStock = false): ?array
    {
        if (preg_match(self::OUTLET_CODE, $outletCode) !== 1) {
            return null;
        }
        $outlet = DB::table('outlets')
            ->join('brands', 'brands.id', '=', 'outlets.brand_id')
            ->where('outlets.code', strtoupper($outletCode))
            ->where('outlets.is_active', true)
            ->first(['outlets.id', 'outlets.code', 'outlets.name', 'outlets.qris_image_path', 'brands.id as brand_id', 'brands.name as brand_name']);
        if ($outlet === null) {
            return null;
        }

        $categories = DB::table('categories')
            ->where('brand_id', $outlet->brand_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])
            ->all();

        $products = $categories === [] ? collect() : DB::table('products')
            ->leftJoin('outlet_products', function ($join) use ($outlet) {
                $join->on('outlet_products.product_id', '=', 'products.id')->where('outlet_products.outlet_id', '=', $outlet->id);
            })
            ->leftJoin('inventory_items', function ($join) use ($outlet) {
                $join->on('inventory_items.id', '=', 'outlet_products.inventory_item_id')->where('inventory_items.outlet_id', '=', $outlet->id);
            })
            ->where('products.brand_id', $outlet->brand_id)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereIn('products.category_id', array_column($categories, 'id'))
            ->orderBy('products.sort_order')
            ->orderBy('products.name')
            ->get([
                'products.id', 'products.name', 'products.slug', 'products.description', 'products.image_path', 'products.category_id',
                DB::raw('coalesce(outlet_products.price_override, products.base_price) as price'),
                DB::raw('coalesce(outlet_products.is_available, true) as manual_available'),
                'inventory_items.id as linked_item_id', 'inventory_items.stock_qty as linked_stock', 'inventory_items.is_active as linked_active',
            ]);

        $productIds = $products->pluck('id')->all();
        $groupLinks = $productIds === [] ? collect() : DB::table('product_modifier_groups')
            ->join('modifier_groups', 'modifier_groups.id', '=', 'product_modifier_groups.group_id')
            ->whereIn('product_modifier_groups.product_id', $productIds)
            ->where('modifier_groups.brand_id', $outlet->brand_id)
            ->orderBy('product_modifier_groups.sort_order')
            ->orderBy('modifier_groups.sort_order')
            ->get(['product_modifier_groups.product_id', 'modifier_groups.id', 'modifier_groups.name', 'modifier_groups.selection', 'modifier_groups.is_required', 'modifier_groups.min_select', 'modifier_groups.max_select']);

        $groupIds = $groupLinks->pluck('id')->unique()->values()->all();
        $options = $groupIds === [] ? collect() : DB::table('modifiers')
            ->whereIn('group_id', $groupIds)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get(['id', 'group_id', 'name', 'price_delta', 'is_default']);

        return [
            'outlet' => ['id' => $outlet->id, 'code' => $outlet->code, 'name' => $outlet->name, 'qrisAvailable' => $outlet->qris_image_path !== null],
            'brand' => ['id' => $outlet->brand_id, 'name' => $outlet->brand_name],
            'categories' => $categories,
            'products' => $products->map(function ($p) use ($groupLinks, $options, $withStock) {
                $groups = $groupLinks->where('product_id', $p->id)->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'selection' => $g->selection,
                    'isRequired' => (bool) $g->is_required,
                    'minSelect' => (int) $g->min_select,
                    'maxSelect' => $g->max_select === null ? null : (int) $g->max_select,
                    'modifiers' => $options->where('group_id', $g->id)->map(fn ($o) => [
                        'id' => $o->id,
                        'name' => $o->name,
                        'priceDelta' => (int) $o->price_delta,
                    ])->values()->all(),
                ])
                    // Grup tanpa opsi aktif tidak bisa dipilih: jangan blokir pesanan.
                    ->filter(fn ($g) => $g['modifiers'] !== [])
                    ->values()
                    ->all();
                $groupIdSet = array_column($groups, 'id');
                $availability = self::availability((bool) $p->manual_available, $p->linked_item_id, $p->linked_stock, $p->linked_active === null ? null : (bool) $p->linked_active);

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'description' => $p->description,
                    'imageUrl' => MenuImages::url($p->image_path),
                    'categoryId' => $p->category_id,
                    'price' => (int) $p->price,
                    'isAvailable' => $availability['effective'],
                    ...($withStock ? ['stockLimit' => $p->linked_item_id === null ? null : max(0, (int) floor((float) ($p->linked_stock ?? 0)))] : []),
                    'groups' => $groups,
                    'defaultModifierIds' => $options
                        ->filter(fn ($o) => $o->is_default && in_array($o->group_id, $groupIdSet, true))
                        ->pluck('id')
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Kalkulasi keranjang di server. Browser hanya mengirim id produk, id modifier,
     * dan jumlah; harga apa pun yang dikirim browser diabaikan dan dihitung ulang dari DB.
     *
     * @param  list<array{productId: string, modifierIds: list<string>, quantity: int}>  $lines
     */
    public static function quote(array $storefront, array $lines): array
    {
        $products = array_column($storefront['products'], null, 'id');
        // Produk jadi: total semua baris produk yang sama tidak boleh melebihi stok.
        $demand = [];
        foreach ($lines as $line) {
            $demand[$line['productId']] = ($demand[$line['productId']] ?? 0) + $line['quantity'];
        }

        $quoted = array_map(function (array $line) use ($products, $demand) {
            $product = $products[$line['productId']] ?? null;
            if ($product === null) {
                return [...$line, 'ok' => false, 'name' => null, 'error' => 'Produk tidak lagi tersedia di menu.'];
            }
            $priced = Pricing::priceSelection($product, $line['modifierIds']);
            if (! $priced['ok']) {
                return [...$line, 'ok' => false, 'name' => $product['name'], 'error' => $priced['error']];
            }
            $limit = $product['stockLimit'] ?? null;
            if ($limit !== null && $demand[$product['id']] > $limit) {
                return [...$line, 'ok' => false, 'name' => $product['name'], 'error' => "Stok tersisa {$limit}. Kurangi jumlahnya."];
            }

            return [
                ...$line,
                'ok' => true,
                'name' => $product['name'],
                'basePrice' => $product['price'],
                'modifiers' => array_map(fn (array $m) => [
                    'modifierId' => $m['modifierId'],
                    'groupName' => $m['groupName'],
                    'name' => $m['name'],
                    'priceDelta' => $m['priceDelta'],
                ], $priced['modifiers']),
                'unitPrice' => $priced['unitPrice'],
                'lineTotal' => $priced['unitPrice'] * $line['quantity'],
            ];
        }, $lines);

        return [
            'ok' => true,
            'lines' => $quoted,
            'total' => array_sum(array_map(fn ($l) => $l['ok'] ? $l['lineTotal'] : 0, $quoted)),
            'itemCount' => array_sum(array_map(fn ($l) => $l['ok'] ? $l['quantity'] : 0, $quoted)),
        ];
    }

    /**
     * Validasi input quote. Hanya productId, modifierIds, dan quantity yang dibaca.
     *
     * @return array{outlet: string, lines: list<array{productId: string, modifierIds: list<string>, quantity: int}>}|null
     */
    public static function quoteInput(mixed $raw): ?array
    {
        if (! is_array($raw) || ! is_string($raw['outlet'] ?? null) || ! is_array($raw['lines'] ?? null)) {
            return null;
        }
        $outlet = trim($raw['outlet']);
        if ($outlet === '' || strlen($outlet) > 32 || ! array_is_list($raw['lines']) || count($raw['lines']) > 50) {
            return null;
        }
        $lines = [];
        foreach ($raw['lines'] as $line) {
            $quantity = $line['quantity'] ?? null;
            $modifierIds = $line['modifierIds'] ?? null;
            if (
                ! is_array($line) || ! CatalogInput::isUuid($line['productId'] ?? null)
                || ! is_int($quantity) || $quantity < 1 || $quantity > Pricing::MAX_LINE_QUANTITY
                || ! is_array($modifierIds) || ! array_is_list($modifierIds) || count($modifierIds) > 30
            ) {
                return null;
            }
            foreach ($modifierIds as $id) {
                if (! CatalogInput::isUuid($id)) {
                    return null;
                }
            }
            $lines[] = ['productId' => strtolower($line['productId']), 'modifierIds' => array_map('strtolower', $modifierIds), 'quantity' => $quantity];
        }

        return ['outlet' => $outlet, 'lines' => $lines];
    }
}
