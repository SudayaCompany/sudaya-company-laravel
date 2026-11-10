<?php

namespace App\Services\Inventory;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogInput;
use App\Support\Access\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * Resep/BOM per outlet (PRD §93, Fase 2): bahan baku & kemasan per 1 porsi menu. Saat pesanan
 * dibayar, bahan dipotong otomatis (InventoryLedger::postSale) tanpa pernah menolak penjualan.
 * Lihat = inventory.view; ubah = inventory.adjust (izin yang sudah ada).
 */
final class Recipes
{
    public const MAX_LINES = 20;

    public function __construct(private AuditLogger $audit) {}

    /** Menu yang tersedia di outlet beserta jumlah bahan resepnya. */
    public function products(User $actor, array $outlet): array
    {
        $this->assertAt($actor, 'inventory.view', $outlet['id']);
        $counts = DB::table('product_recipe_items')->where('outlet_id', $outlet['id'])->groupBy('product_id')
            ->selectRaw('product_id, count(*) as n')->pluck('n', 'product_id');

        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('outlet_products', fn ($j) => $j->on('outlet_products.product_id', '=', 'products.id')->where('outlet_products.outlet_id', '=', $outlet['id']))
            ->where('products.company_id', $actor->company_id)
            ->whereNull('products.deleted_at')
            ->where('products.brand_id', DB::table('outlets')->where('id', $outlet['id'])->value('brand_id'))
            ->orderBy('categories.name')->orderBy('products.name')
            ->get(['products.id', 'products.name', 'products.is_active', 'categories.name as category', 'outlet_products.inventory_item_id'])
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'category' => $p->category, 'isActive' => (bool) $p->is_active,
                'lines' => (int) ($counts[$p->id] ?? 0), 'linkedFinishedGood' => $p->inventory_item_id !== null,
            ])->all();
    }

    public function get(User $actor, array $outlet, mixed $productId): array
    {
        $this->assertAt($actor, 'inventory.view', $outlet['id']);
        $product = $this->product($actor, $outlet['id'], $productId);
        $lines = DB::table('product_recipe_items as r')->join('inventory_items as i', 'i.id', '=', 'r.item_id')
            ->where('r.outlet_id', $outlet['id'])->where('r.product_id', $product->id)->orderBy('i.name')
            ->get(['r.item_id', 'r.quantity', 'i.name', 'i.unit', 'i.purchase_price']);

        return [
            'product' => ['id' => $product->id, 'name' => $product->name, 'price' => (int) $product->base_price],
            'canEdit' => $actor->can('inventory.adjust', AccessScope::outlet($outlet['id'])),
            'lines' => $lines->map(fn ($l) => [
                'itemId' => $l->item_id, 'name' => $l->name, 'unit' => $l->unit,
                'quantity' => rtrim(rtrim(number_format(Qty::fromDb($l->quantity) / 1000, 3, '.', ''), '0'), '.'),
                'cost' => $l->purchase_price === null ? null : (int) round(Qty::fromDb($l->quantity) / 1000 * (float) $l->purchase_price),
            ])->all(),
            'items' => DB::table('inventory_items')->where('company_id', $actor->company_id)->where('outlet_id', $outlet['id'])->where('is_active', true)
                ->where('id', '<>', $product->inventory_item_id ?? '00000000-0000-0000-0000-000000000000')
                ->orderBy('name')->get(['id', 'name', 'unit', 'kind'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'kind' => $i->kind, 'units' => Qty::compatible($i->unit)])->all(),
        ];
    }

    /** Ganti seluruh resep menu di outlet (lines kosong = hapus resep). */
    public function save(User $actor, array $raw, AuditContext $context): int
    {
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id'])
            ?? throw AccessException::notFound();
        $this->assertAt($actor, 'inventory.adjust', $outlet->id);
        $product = $this->product($actor, $outlet->id, $raw['productId'] ?? null);
        $rawLines = is_array($raw['lines'] ?? null) ? array_values($raw['lines']) : [];
        if (count($rawLines) > self::MAX_LINES) {
            throw new AccessException('invalid', 'Resep maksimal '.self::MAX_LINES.' bahan.');
        }
        $lines = [];
        foreach ($rawLines as $line) {
            $item = DB::table('inventory_items')->where('id', CatalogInput::uuid($line['itemId'] ?? null))->where('company_id', $actor->company_id)
                ->where('outlet_id', $outlet->id)->first(['id', 'name', 'unit'])
                ?? throw new AccessException('invalid', 'Pilih bahan dari item stok outlet ini.');
            if ($item->id === $product->inventory_item_id) {
                throw new AccessException('invalid', "{$item->name} sudah tertaut sebagai produk jadi menu ini; jangan dimasukkan ke resep.");
            }
            if (isset($lines[$item->id])) {
                throw new AccessException('invalid', "{$item->name} tercantum dua kali.");
            }
            $lines[$item->id] = Qty::parse($line['quantity'] ?? null, is_string($line['unit'] ?? null) ? $line['unit'] : $item->unit, $item->unit);
        }

        DB::transaction(function () use ($actor, $outlet, $product, $lines, $context) {
            $before = DB::table('product_recipe_items')->where('outlet_id', $outlet->id)->where('product_id', $product->id)->count();
            DB::table('product_recipe_items')->where('outlet_id', $outlet->id)->where('product_id', $product->id)->delete();
            foreach ($lines as $itemId => $milli) {
                DB::table('product_recipe_items')->insert([
                    'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id,
                    'product_id' => $product->id, 'item_id' => $itemId, 'quantity' => Qty::toDb($milli),
                ]);
            }
            $this->audit->record([
                'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'actor_id' => $actor->id,
                'action' => 'inventory.recipe_saved', 'entity_type' => 'product', 'entity_id' => $product->id,
                'before' => ['lines' => $before], 'after' => ['product' => $product->name, 'lines' => array_map(fn ($id, $m) => ['itemId' => $id, 'quantity' => Qty::toDb($m)], array_keys($lines), $lines)],
            ], $context);
        });

        return count($lines);
    }

    private function product(User $actor, string $outletId, mixed $productId): object
    {
        $brandId = DB::table('outlets')->where('id', $outletId)->value('brand_id');

        return DB::table('products')
            ->leftJoin('outlet_products', fn ($j) => $j->on('outlet_products.product_id', '=', 'products.id')->where('outlet_products.outlet_id', '=', $outletId))
            ->where('products.id', CatalogInput::uuid($productId))->where('products.company_id', $actor->company_id)
            ->where('products.brand_id', $brandId)->whereNull('products.deleted_at')
            ->first(['products.id', 'products.name', 'products.base_price', 'outlet_products.inventory_item_id'])
            ?? throw AccessException::notFound();
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
