<?php

namespace App\Services\Catalog;

use App\Models\User;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Support\Facades\DB;

/**
 * Ekspor menu satu brand ke spreadsheet (keputusan owner 8 November: input dari web,
 * ekspor bila dibutuhkan). Izin sama dengan melihat menu admin; tanpa kolom internal.
 */
final class MenuExport
{
    public function __construct(private CatalogAdmin $catalog) {}

    /** @return array{filename: string, bytes: string, rows: int} */
    public function xlsx(User $actor, ?string $brandId = null): array
    {
        // scope() memeriksa product.view dan membatasi ke brand + outlet milik company actor.
        $scope = $this->catalog->scope($actor, $brandId);
        $brand = $scope['brand'];
        $outlets = $scope['outlets'];

        $products = DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.company_id', $actor->company_id)
            ->where('products.brand_id', $brand['id'])
            ->whereNull('products.deleted_at')
            ->whereNull('categories.deleted_at')
            ->orderBy('categories.sort_order')->orderBy('categories.name')
            ->orderBy('products.sort_order')->orderBy('products.name')
            ->get(['products.id', 'products.name', 'products.sku', 'products.description', 'products.base_price', 'products.is_active', 'categories.name as category']);

        $ids = $products->pluck('id')->all();
        $groups = $ids === [] ? collect() : DB::table('product_modifier_groups')
            ->join('modifier_groups', 'modifier_groups.id', '=', 'product_modifier_groups.group_id')
            ->whereIn('product_modifier_groups.product_id', $ids)
            ->orderBy('product_modifier_groups.sort_order')
            ->get(['product_modifier_groups.product_id', 'modifier_groups.name'])
            ->groupBy('product_id');
        $availability = $ids === [] || $outlets === [] ? collect() : DB::table('outlet_products')
            ->whereIn('product_id', $ids)
            ->whereIn('outlet_id', array_column($outlets, 'id'))
            ->get(['product_id', 'outlet_id', 'is_available', 'price_override'])
            ->groupBy('product_id');

        $header = ['Kategori', 'Nama menu', 'SKU', 'Deskripsi', 'Harga dasar (Rp)', 'Status', 'Varian & tambahan'];
        foreach ($outlets as $outlet) {
            $header[] = "Tersedia di {$outlet['name']}";
            $header[] = "Harga khusus {$outlet['name']} (Rp)";
        }

        $rows = $products->map(function ($p) use ($groups, $availability, $outlets) {
            $row = [
                $p->category,
                $p->name,
                $p->sku,
                $p->description,
                (int) $p->base_price,
                $p->is_active ? 'Aktif' : 'Nonaktif',
                ($groups[$p->id] ?? collect())->pluck('name')->implode(', '),
            ];
            $perOutlet = ($availability[$p->id] ?? collect())->keyBy('outlet_id');
            foreach ($outlets as $outlet) {
                $setting = $perOutlet[$outlet['id']] ?? null;
                // Tanpa baris outlet_products, produk dianggap tersedia dengan harga dasar.
                $row[] = $setting === null || $setting->is_available ? 'Tersedia' : 'Habis';
                $row[] = $setting?->price_override === null ? null : (int) $setting->price_override;
            }

            return $row;
        })->values()->all();

        $widths = [18, 32, 14, 40, 18, 12, 32, ...array_fill(0, count($outlets) * 2, 22)];
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($brand['name'])), '-') ?: 'menu';

        return [
            'filename' => "menu-{$slug}-".now()->format('Y-m-d').'.xlsx',
            'bytes' => XlsxWriter::build('Menu', $header, $rows, $widths),
            'rows' => count($rows),
        ];
    }
}
