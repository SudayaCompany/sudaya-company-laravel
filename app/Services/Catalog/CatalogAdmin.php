<?php

namespace App\Services\Catalog;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Support\Access\AccessScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operasi admin katalog (kategori, produk, modifier, ketersediaan). Padanan
 * lib/catalog/admin-ops.ts: actor dari sesi, permission dan scope dicek ulang di
 * sini, input divalidasi CatalogInput, perubahan dicatat di audit.
 *
 * Data master menu berlaku per brand, sehingga mengubahnya butuh permission
 * company-wide. Sold out berlaku per outlet, jadi cukup permission di outlet tsb.
 */
final class CatalogAdmin
{
    public const PRODUCT_PAGE_SIZE = 20;

    private const UNIQUE_MESSAGES = [
        'products_brand_slug_unique' => 'Slug produk sudah dipakai produk lain.',
        'products_brand_sku_unique' => 'SKU sudah dipakai produk lain.',
        'categories_brand_slug_unique' => 'Slug kategori sudah dipakai kategori lain.',
    ];

    public function __construct(
        private AuditLogger $audit,
        private AccessLoader $access,
        private MenuImageStorage $storage,
        private Storefront $storefront,
    ) {}

    // --- Brand & outlet ------------------------------------------------------------

    /**
     * Brand aktif untuk halaman admin (default: brand pertama company), dan outlet
     * brand itu yang bisa diakses actor (untuk status sold out).
     *
     * @return array{brand: array{id: string, name: string}, brands: list<array{id: string, name: string}>, outlets: list<array{id: string, code: string, name: string}>}
     */
    public function scope(User $actor, ?string $brandId = null): array
    {
        $this->assertView($actor);
        $brands = DB::table('brands')
            ->where('company_id', $actor->company_id)
            ->orderBy('code')
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])
            ->all();
        $brand = $brandId === null
            ? ($brands[0] ?? null)
            : (array_values(array_filter($brands, fn ($b) => $b['id'] === $brandId))[0] ?? null);
        if ($brand === null) {
            throw AccessException::notFound();
        }

        $outlets = array_values(array_map(
            fn (array $o) => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name']],
            array_filter($this->access->accessibleOutlets($actor), fn (array $o) => $o['brandId'] === $brand['id']),
        ));

        return ['brand' => $brand, 'brands' => $brands, 'outlets' => $outlets];
    }

    // --- Kategori ------------------------------------------------------------------

    /** @return list<array{id: string, name: string, slug: string, sortOrder: int, isActive: bool, productCount: int}> */
    public function listCategories(User $actor, string $brandId): array
    {
        $this->assertView($actor);

        return DB::table('categories')
            ->leftJoin('products', function ($join) {
                $join->on('products.category_id', '=', 'categories.id')->whereNull('products.deleted_at');
            })
            ->where('categories.brand_id', $brandId)
            ->where('categories.company_id', $actor->company_id)
            ->whereNull('categories.deleted_at')
            ->groupBy('categories.id')
            ->orderBy('categories.sort_order')
            ->orderBy('categories.name')
            ->get(['categories.id', 'categories.name', 'categories.slug', 'categories.sort_order', 'categories.is_active', DB::raw('count(products.id)::int as product_count')])
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'sortOrder' => (int) $row->sort_order,
                'isActive' => (bool) $row->is_active,
                'productCount' => (int) $row->product_count,
            ])
            ->all();
    }

    /** @return array{id: string, brandId: string} */
    public function createCategory(User $actor, string $brandId, array $raw, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.create');
        $this->loadBrand($actor, $brandId);
        $input = CatalogInput::category($raw);

        return $this->mutate($brandId, fn () => DB::transaction(function () use ($actor, $brandId, $input, $context) {
            $id = DB::table('categories')->insertGetId([
                'company_id' => $actor->company_id,
                'brand_id' => $brandId,
                'name' => $input['name'],
                'slug' => $input['slug'],
                'sort_order' => $input['sortOrder'],
                'is_active' => $input['isActive'],
            ]);
            $this->record($actor, $brandId, 'category.created', 'category', $id, after: $input, context: $context);

            return ['id' => $id, 'brandId' => $brandId];
        }));
    }

    /** @return array{id: string, brandId: string} */
    public function updateCategory(User $actor, mixed $categoryId, array $raw, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.edit');
        $current = $this->loadCategory($actor, CatalogInput::uuid($categoryId));
        $input = CatalogInput::category($raw);
        $changes = $this->diff([
            'name' => $current->name,
            'slug' => $current->slug,
            'sortOrder' => (int) $current->sort_order,
            'isActive' => (bool) $current->is_active,
        ], $input);
        $result = ['id' => $current->id, 'brandId' => $current->brand_id];
        if ($changes['after'] === []) {
            return $result;
        }

        return $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $input, $changes, $context, $result) {
            DB::table('categories')->where('id', $current->id)->update([
                'name' => $input['name'],
                'slug' => $input['slug'],
                'sort_order' => $input['sortOrder'],
                'is_active' => $input['isActive'],
                'updated_at' => DB::raw('now()'),
            ]);
            $this->record($actor, $current->brand_id, 'category.updated', 'category', $current->id, $changes['before'], $changes['after'], context: $context);

            return $result;
        }));
    }

    /** @return array{id: string, brandId: string} */
    public function deleteCategory(User $actor, mixed $categoryId, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.delete');
        $current = $this->loadCategory($actor, CatalogInput::uuid($categoryId));

        return $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $context) {
            $total = DB::table('products')->where('category_id', $current->id)->whereNull('deleted_at')->count();
            if ($total > 0) {
                throw new AccessException('conflict', "Kategori masih berisi {$total} produk. Pindahkan atau hapus produknya dulu.");
            }
            DB::table('categories')->where('id', $current->id)->update([
                'deleted_at' => DB::raw('now()'),
                'is_active' => false,
                'updated_at' => DB::raw('now()'),
            ]);
            $this->record($actor, $current->brand_id, 'category.deleted', 'category', $current->id, before: ['name' => $current->name, 'slug' => $current->slug], context: $context);

            return ['id' => $current->id, 'brandId' => $current->brand_id];
        }));
    }

    // --- Modifier ------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listModifierGroups(User $actor, string $brandId): array
    {
        $this->assertView($actor);
        $groups = DB::table('modifier_groups')
            ->where('brand_id', $brandId)
            ->where('company_id', $actor->company_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'selection', 'is_required', 'min_select', 'max_select', 'sort_order']);
        if ($groups->isEmpty()) {
            return [];
        }
        $groupIds = $groups->pluck('id')->all();

        $options = DB::table('modifiers')
            ->whereIn('group_id', $groupIds)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get(['id', 'group_id', 'name', 'price_delta', 'is_default', 'is_active'])
            ->groupBy('group_id');
        $usage = DB::table('product_modifier_groups')
            ->join('products', 'products.id', '=', 'product_modifier_groups.product_id')
            ->whereIn('product_modifier_groups.group_id', $groupIds)
            ->whereNull('products.deleted_at')
            ->groupBy('product_modifier_groups.group_id')
            ->get(['product_modifier_groups.group_id', DB::raw('count(*)::int as total')])
            ->pluck('total', 'group_id');

        return $groups->map(fn ($group) => [
            'id' => $group->id,
            'name' => $group->name,
            'selection' => $group->selection,
            'isRequired' => (bool) $group->is_required,
            'minSelect' => (int) $group->min_select,
            'maxSelect' => $group->max_select === null ? null : (int) $group->max_select,
            'sortOrder' => (int) $group->sort_order,
            'productCount' => (int) ($usage[$group->id] ?? 0),
            'options' => ($options[$group->id] ?? collect())->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'priceDelta' => (int) $o->price_delta,
                'isDefault' => (bool) $o->is_default,
                'isActive' => (bool) $o->is_active,
            ])->values()->all(),
        ])->all();
    }

    /** @return array{id: string, brandId: string} */
    public function createModifierGroup(User $actor, string $brandId, array $raw, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.create');
        $this->loadBrand($actor, $brandId);
        $input = CatalogInput::modifierGroup($raw);

        return $this->mutate($brandId, fn () => DB::transaction(function () use ($actor, $brandId, $input, $context) {
            $id = DB::table('modifier_groups')->insertGetId([
                ...$this->groupColumns($input),
                'company_id' => $actor->company_id,
                'brand_id' => $brandId,
            ]);
            foreach ($input['options'] as $index => $option) {
                DB::table('modifiers')->insert([...$this->optionColumns($option, $index), 'group_id' => $id]);
            }
            $this->record($actor, $brandId, 'modifier_group.created', 'modifier_group', $id, after: [
                ...$this->groupSummary($input),
                'options' => $this->optionSummary($input['options']),
            ], context: $context);

            return ['id' => $id, 'brandId' => $brandId];
        }));
    }

    /** @return array{id: string, brandId: string} */
    public function updateModifierGroup(User $actor, mixed $groupId, array $raw, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.edit');
        $current = $this->loadModifierGroup($actor, CatalogInput::uuid($groupId));
        $input = CatalogInput::modifierGroup($raw);

        return $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $input, $context) {
            $existing = DB::table('modifiers')->where('group_id', $current->id)->orderBy('sort_order')->get();
            $existingIds = $existing->pluck('id')->all();
            foreach ($input['options'] as $option) {
                // Id opsi milik grup lain berarti request dimanipulasi.
                if ($option['id'] !== null && ! in_array($option['id'], $existingIds, true)) {
                    throw AccessException::notFound();
                }
            }

            DB::table('modifier_groups')->where('id', $current->id)->update([...$this->groupColumns($input), 'updated_at' => DB::raw('now()')]);

            $keep = array_filter(array_column($input['options'], 'id'));
            $removed = array_values(array_diff($existingIds, $keep));
            if ($removed !== []) {
                // Aman: order_item_modifiers menyimpan snapshot nama dan harga.
                DB::table('modifiers')->whereIn('id', $removed)->delete();
            }
            foreach ($input['options'] as $index => $option) {
                $values = $this->optionColumns($option, $index);
                if ($option['id'] !== null) {
                    DB::table('modifiers')->where('id', $option['id'])->update([...$values, 'updated_at' => DB::raw('now()')]);
                } else {
                    DB::table('modifiers')->insert([...$values, 'group_id' => $current->id]);
                }
            }

            $before = [
                'name' => $current->name,
                'selection' => $current->selection,
                'isRequired' => (bool) $current->is_required,
                'minSelect' => (int) $current->min_select,
                'maxSelect' => $current->max_select === null ? null : (int) $current->max_select,
                'options' => $this->optionSummary($existing->map(fn ($m) => [
                    'name' => $m->name,
                    'priceDelta' => (int) $m->price_delta,
                    'isActive' => (bool) $m->is_active,
                ])->all()),
            ];
            $this->record($actor, $current->brand_id, 'modifier_group.updated', 'modifier_group', $current->id, $before, [
                ...$this->groupSummary($input),
                'options' => $this->optionSummary($input['options']),
            ], context: $context);

            return ['id' => $current->id, 'brandId' => $current->brand_id];
        }));
    }

    /** @return array{id: string, brandId: string} */
    public function deleteModifierGroup(User $actor, mixed $groupId, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.delete');
        $current = $this->loadModifierGroup($actor, CatalogInput::uuid($groupId));

        return $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $context) {
            // Cascade ke modifiers dan product_modifier_groups. Histori order aman
            // karena order_item_modifiers menyimpan snapshot.
            DB::table('modifier_groups')->where('id', $current->id)->delete();
            $this->record($actor, $current->brand_id, 'modifier_group.deleted', 'modifier_group', $current->id, before: ['name' => $current->name], context: $context);

            return ['id' => $current->id, 'brandId' => $current->brand_id];
        }));
    }

    // --- Produk --------------------------------------------------------------------

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, pageCount: int} */
    public function listProducts(User $actor, string $brandId, ?string $outletId, array $params): array
    {
        $this->assertView($actor);
        // Manual sold out (toggle admin) dan stok produk jadi dinilai terpisah;
        // filter sold out memakai ketersediaan efektif (lihat Storefront::availability).
        $manual = 'coalesce(outlet_products.is_available, true)';
        $inventoryUnavailable = '(inventory_items.id is not null and (not inventory_items.is_active or inventory_items.stock_qty <= 0))';

        $base = DB::table('products')
            ->leftJoin('outlet_products', function ($join) use ($outletId) {
                $join->on('outlet_products.product_id', '=', 'products.id');
                // Tanpa outlet terpilih, join tidak pernah cocok sehingga dianggap tersedia.
                $outletId === null ? $join->whereRaw('false') : $join->where('outlet_products.outlet_id', '=', $outletId);
            })
            ->leftJoin('inventory_items', function ($join) {
                $join->on('inventory_items.id', '=', 'outlet_products.inventory_item_id')
                    ->on('inventory_items.outlet_id', '=', 'outlet_products.outlet_id');
            })
            ->where('products.brand_id', $brandId)
            ->where('products.company_id', $actor->company_id)
            ->whereNull('products.deleted_at')
            ->when($params['q'] !== null, function ($query) use ($params) {
                $pattern = '%'.addcslashes($params['q'], '\\%_').'%';
                $query->where(fn ($q) => $q->where('products.name', 'ilike', $pattern)->orWhere('products.sku', 'ilike', $pattern));
            })
            ->when($params['category'] !== null, fn ($q) => $q->where('products.category_id', $params['category']))
            ->when($params['status'] !== 'all', fn ($q) => $q->where('products.is_active', $params['status'] === 'active'))
            ->when($outletId !== null && $params['availability'] !== 'all', fn ($q) => $q->whereRaw(
                "({$manual} and not {$inventoryUnavailable}) = ?",
                [$params['availability'] === 'available'],
            ));

        $total = (clone $base)->count();
        $pageCount = max(1, (int) ceil($total / self::PRODUCT_PAGE_SIZE));
        $page = min($params['page'], $pageCount);
        $sort = ['price' => 'products.base_price', 'updated' => 'products.updated_at'][$params['sort']] ?? 'products.name';

        $rows = $base
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->orderBy($sort, $params['dir'])
            ->orderBy('products.id')
            ->limit(self::PRODUCT_PAGE_SIZE)
            ->offset(($page - 1) * self::PRODUCT_PAGE_SIZE)
            ->get([
                'products.id', 'products.name', 'products.sku', 'products.slug', 'products.image_path',
                'products.base_price', 'products.is_active', 'products.updated_at', 'categories.name as category_name',
                DB::raw("{$manual} as is_available"),
                DB::raw("{$inventoryUnavailable} as inventory_unavailable"),
                DB::raw('coalesce(inventory_items.stock_qty > 0 and inventory_items.stock_qty <= inventory_items.minimum_stock, false) as low_stock'),
                'inventory_items.stock_qty', 'inventory_items.unit as stock_unit',
            ])
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'sku' => $row->sku,
                'slug' => $row->slug,
                'imageUrl' => MenuImages::url($row->image_path),
                'basePrice' => (int) $row->base_price,
                'isActive' => (bool) $row->is_active,
                'categoryName' => $row->category_name,
                'isAvailable' => (bool) $row->is_available,
                'inventoryUnavailable' => (bool) $row->inventory_unavailable,
                'lowStock' => (bool) $row->low_stock,
                'stockQty' => $row->stock_qty,
                'stockUnit' => $row->stock_unit,
            ])
            ->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pageCount' => $pageCount];
    }

    /** @return array<string, mixed> */
    public function productForEdit(User $actor, mixed $productId): array
    {
        $this->assertMaster($actor, 'product.edit');
        $product = $this->loadProduct($actor, CatalogInput::uuid($productId));

        return [
            'id' => $product->id,
            'brandId' => $product->brand_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'categoryId' => $product->category_id,
            'description' => $product->description,
            'basePrice' => (int) $product->base_price,
            'isActive' => (bool) $product->is_active,
            'imageUrl' => MenuImages::url($product->image_path),
            'modifierGroupIds' => $this->productGroupIds($product->id),
        ];
    }

    /**
     * @param  array{bytes: string, ext: string, contentType: string}|null  $image
     * @return array{id: string, brandId: string}
     */
    public function createProduct(User $actor, string $brandId, array $raw, ?array $image, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.create');
        $this->loadBrand($actor, $brandId);
        $input = CatalogInput::product($raw);
        $this->assertRelations($brandId, $input);

        $imagePath = $image === null ? null : MenuImages::path($actor->company_id, $brandId, $image['ext']);
        if ($image !== null) {
            $this->storage->upload($imagePath, $image['bytes'], $image['contentType']);
        }

        try {
            return $this->mutate($brandId, fn () => DB::transaction(function () use ($actor, $brandId, $input, $imagePath, $context) {
                $id = DB::table('products')->insertGetId([
                    ...$this->productColumns($input),
                    'image_path' => $imagePath,
                    'company_id' => $actor->company_id,
                    'brand_id' => $brandId,
                ]);
                $this->linkModifierGroups($id, $input['modifierGroupIds']);
                $this->record($actor, $brandId, 'product.created', 'product', $id, after: [
                    ...$this->productFields($input),
                    'hasImage' => $imagePath !== null,
                ], context: $context);

                return ['id' => $id, 'brandId' => $brandId];
            }));
        } catch (Throwable $error) {
            // Foto sudah terunggah tapi produk gagal disimpan: hapus lagi.
            $this->removeQuietly($imagePath);
            throw $error;
        }
    }

    /**
     * @param  array{image: array{bytes: string, ext: string, contentType: string}|null, remove: bool}  $imageChange
     * @return array{id: string, brandId: string}
     */
    public function updateProduct(User $actor, mixed $productId, array $raw, array $imageChange, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.edit');
        $current = $this->loadProduct($actor, CatalogInput::uuid($productId));
        $input = CatalogInput::product($raw);
        $this->assertRelations($current->brand_id, $input);

        $image = $imageChange['image'];
        $newPath = $image === null ? null : MenuImages::path($actor->company_id, $current->brand_id, $image['ext']);
        $nextPath = $newPath ?? ($imageChange['remove'] ? null : $current->image_path);
        if ($image !== null) {
            $this->storage->upload($newPath, $image['bytes'], $image['contentType']);
        }

        try {
            $result = $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $input, $newPath, $nextPath, $context) {
                $oldGroups = $this->productGroupIds($current->id);
                DB::table('products')->where('id', $current->id)->update([
                    ...$this->productColumns($input),
                    'image_path' => $nextPath,
                    'updated_at' => DB::raw('now()'),
                ]);
                $this->linkModifierGroups($current->id, $input['modifierGroupIds']);

                $old = $this->productFields([
                    'name' => $current->name,
                    'slug' => $current->slug,
                    'sku' => $current->sku,
                    'description' => $current->description,
                    'categoryId' => $current->category_id,
                    'basePrice' => (int) $current->base_price,
                    'isActive' => (bool) $current->is_active,
                ]);
                $new = $this->productFields($input);
                $changes = $this->diff(
                    [...array_diff_key($old, ['basePrice' => 1]), 'modifierGroupIds' => $oldGroups],
                    [...array_diff_key($new, ['basePrice' => 1]), 'modifierGroupIds' => $input['modifierGroupIds']],
                );
                if ($changes['after'] !== []) {
                    $this->record($actor, $current->brand_id, 'product.updated', 'product', $current->id, $changes['before'], $changes['after'], context: $context);
                }
                // PRD §72: perubahan harga dicatat sebagai event tersendiri.
                if ($new['basePrice'] !== $old['basePrice']) {
                    $this->record($actor, $current->brand_id, 'product.price_changed', 'product', $current->id, ['basePrice' => $old['basePrice']], ['basePrice' => $new['basePrice']], context: $context);
                }
                if ($nextPath !== $current->image_path) {
                    $this->record($actor, $current->brand_id, $newPath !== null ? 'product.image_changed' : 'product.image_removed', 'product', $current->id, context: $context);
                }

                return ['id' => $current->id, 'brandId' => $current->brand_id];
            }));
        } catch (Throwable $error) {
            $this->removeQuietly($newPath);
            throw $error;
        }

        // Foto lama dihapus setelah DB tersimpan, jadi referensi aktif tidak pernah rusak.
        if ($current->image_path !== null && $current->image_path !== $nextPath) {
            $this->removeQuietly($current->image_path);
        }

        return $result;
    }

    /** @return array{id: string, brandId: string} */
    public function setProductActive(User $actor, array $raw, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.edit');
        $productId = CatalogInput::uuid($raw['productId'] ?? null);
        $isActive = CatalogInput::boolean($raw['isActive'] ?? null);
        $current = $this->loadProduct($actor, $productId);
        $result = ['id' => $current->id, 'brandId' => $current->brand_id];
        if ((bool) $current->is_active === $isActive) {
            return $result;
        }

        return $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $isActive, $context, $result) {
            DB::table('products')->where('id', $current->id)->update(['is_active' => $isActive, 'updated_at' => DB::raw('now()')]);
            $this->record($actor, $current->brand_id, 'product.status_changed', 'product', $current->id, ['isActive' => (bool) $current->is_active], ['isActive' => $isActive], context: $context);

            return $result;
        }));
    }

    /** Soft delete: histori order tetap merujuk produk ini. */
    public function deleteProduct(User $actor, mixed $productId, AuditContext $context): array
    {
        $this->assertMaster($actor, 'product.delete');
        $current = $this->loadProduct($actor, CatalogInput::uuid($productId));

        return $this->mutate($current->brand_id, fn () => DB::transaction(function () use ($actor, $current, $context) {
            DB::table('products')->where('id', $current->id)->update([
                'deleted_at' => DB::raw('now()'),
                'is_active' => false,
                'updated_at' => DB::raw('now()'),
            ]);
            $this->record($actor, $current->brand_id, 'product.deleted', 'product', $current->id, before: [
                'name' => $current->name,
                'sku' => $current->sku,
                'basePrice' => (int) $current->base_price,
            ], context: $context);

            return ['id' => $current->id, 'brandId' => $current->brand_id];
        }));
    }

    /** Manual sold out per outlet (PRD §73). Cukup permission di outlet tsb. */
    public function setProductAvailability(User $actor, array $raw, AuditContext $context): array
    {
        $productId = CatalogInput::uuid($raw['productId'] ?? null);
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $isAvailable = CatalogInput::boolean($raw['isAvailable'] ?? null);
        if (! $actor->can('product.edit', AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }

        $product = $this->loadProduct($actor, $productId);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id']);
        if ($outlet === null || $outlet->brand_id !== $product->brand_id) {
            throw AccessException::notFound();
        }

        return $this->mutate($product->brand_id, fn () => DB::transaction(function () use ($actor, $product, $outlet, $isAvailable, $context) {
            $before = DB::table('outlet_products')->where('outlet_id', $outlet->id)->where('product_id', $product->id)->lockForUpdate()->value('is_available');
            $wasAvailable = $before === null ? true : (bool) $before;
            DB::table('outlet_products')->upsert(
                [['outlet_id' => $outlet->id, 'product_id' => $product->id, 'is_available' => $isAvailable]],
                ['outlet_id', 'product_id'],
                ['is_available'],
            );
            if ($wasAvailable !== $isAvailable) {
                $this->audit->record([
                    'company_id' => $actor->company_id,
                    'brand_id' => $product->brand_id,
                    'outlet_id' => $outlet->id,
                    'actor_id' => $actor->id,
                    'action' => 'product.availability_changed',
                    'entity_type' => 'product',
                    'entity_id' => $product->id,
                    'before' => ['isAvailable' => $wasAvailable],
                    'after' => ['isAvailable' => $isAvailable],
                ], $context);
            }

            return ['id' => $product->id, 'brandId' => $product->brand_id];
        }));
    }

    // --- Bantuan -------------------------------------------------------------------

    private function assertView(User $actor): void
    {
        if (! $actor->can('product.view')) {
            throw AccessException::forbidden();
        }
    }

    /** Data master menu: wajib company-wide. */
    private function assertMaster(User $actor, string $permission): void
    {
        if (! $actor->can($permission, AccessScope::company())) {
            throw AccessException::forbidden();
        }
    }

    private function loadBrand(User $actor, string $brandId): void
    {
        if (! CatalogInput::isUuid($brandId) || ! DB::table('brands')->where('id', $brandId)->where('company_id', $actor->company_id)->exists()) {
            throw AccessException::notFound();
        }
    }

    private function loadCategory(User $actor, string $id): object
    {
        return DB::table('categories')->where('id', $id)->where('company_id', $actor->company_id)->whereNull('deleted_at')->first()
            ?? throw AccessException::notFound();
    }

    private function loadModifierGroup(User $actor, string $id): object
    {
        return DB::table('modifier_groups')->where('id', $id)->where('company_id', $actor->company_id)->first()
            ?? throw AccessException::notFound();
    }

    private function loadProduct(User $actor, string $id): object
    {
        return DB::table('products')->where('id', $id)->where('company_id', $actor->company_id)->whereNull('deleted_at')->first()
            ?? throw AccessException::notFound();
    }

    /** Kategori dan grup modifier wajib milik brand yang sama dengan produk. */
    private function assertRelations(string $brandId, array $input): void
    {
        $category = DB::table('categories')->where('id', $input['categoryId'])->where('brand_id', $brandId)->whereNull('deleted_at')->exists();
        if (! $category) {
            throw new AccessException('invalid', 'Kategori tidak valid.');
        }
        if ($input['modifierGroupIds'] !== []) {
            $found = DB::table('modifier_groups')->whereIn('id', $input['modifierGroupIds'])->where('brand_id', $brandId)->count();
            if ($found !== count($input['modifierGroupIds'])) {
                throw new AccessException('invalid', 'Grup pilihan tidak valid.');
            }
        }
    }

    /** @return list<string> */
    private function productGroupIds(string $productId): array
    {
        return DB::table('product_modifier_groups')->where('product_id', $productId)->orderBy('sort_order')->pluck('group_id')->all();
    }

    private function linkModifierGroups(string $productId, array $groupIds): void
    {
        DB::table('product_modifier_groups')->where('product_id', $productId)->delete();
        foreach ($groupIds as $sortOrder => $groupId) {
            DB::table('product_modifier_groups')->insert(['product_id' => $productId, 'group_id' => $groupId, 'sort_order' => $sortOrder]);
        }
    }

    /** Kolom produk yang boleh diubah lewat form. */
    private function productFields(array $input): array
    {
        return [
            'name' => $input['name'],
            'slug' => $input['slug'],
            'sku' => $input['sku'],
            'description' => $input['description'],
            'categoryId' => $input['categoryId'],
            'basePrice' => $input['basePrice'],
            'isActive' => $input['isActive'],
        ];
    }

    private function productColumns(array $input): array
    {
        return [
            'name' => $input['name'],
            'slug' => $input['slug'],
            'sku' => $input['sku'],
            'description' => $input['description'],
            'category_id' => $input['categoryId'],
            'base_price' => $input['basePrice'],
            'is_active' => $input['isActive'],
        ];
    }

    private function groupColumns(array $input): array
    {
        return [
            'name' => $input['name'],
            'selection' => $input['selection'],
            'is_required' => $input['isRequired'],
            'min_select' => $input['minSelect'],
            'max_select' => $input['maxSelect'],
            'sort_order' => $input['sortOrder'],
        ];
    }

    private function groupSummary(array $input): array
    {
        return array_intersect_key($input, array_flip(['name', 'selection', 'isRequired', 'minSelect', 'maxSelect', 'sortOrder']));
    }

    private function optionColumns(array $option, int $index): array
    {
        return [
            'name' => $option['name'],
            'price_delta' => $option['priceDelta'],
            'is_default' => $option['isDefault'],
            'is_active' => $option['isActive'],
            'sort_order' => $index,
        ];
    }

    /** @return list<string> mis. "Large (+6000)" atau "Oat (+0) [nonaktif]" */
    private function optionSummary(array $options): array
    {
        return array_map(
            fn (array $o) => "{$o['name']} (".($o['priceDelta'] >= 0 ? '+' : '')."{$o['priceDelta']})".($o['isActive'] ? '' : ' [nonaktif]'),
            array_values($options),
        );
    }

    /** @return array{before: array<string, mixed>, after: array<string, mixed>} hanya field yang berubah */
    private function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];
        foreach ($after as $key => $value) {
            if (json_encode($before[$key] ?? null) !== json_encode($value)) {
                $changedBefore[$key] = $before[$key] ?? null;
                $changedAfter[$key] = $value;
            }
        }

        return ['before' => $changedBefore, 'after' => $changedAfter];
    }

    private function record(User $actor, string $brandId, string $action, string $entityType, string $entityId, ?array $before = null, ?array $after = null, ?AuditContext $context = null): void
    {
        $this->audit->record([
            'company_id' => $actor->company_id,
            'brand_id' => $brandId,
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
        ], $context);
    }

    /**
     * Menjalankan mutasi, mengubah pelanggaran unique index menjadi pesan yang
     * jelas, lalu membuang cache menu customer brand tsb.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    private function mutate(string $brandId, callable $mutation): mixed
    {
        try {
            $result = $mutation();
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                preg_match('/unique constraint "([^"]+)"/', $error->getMessage(), $match);
                throw new AccessException('conflict', self::UNIQUE_MESSAGES[$match[1] ?? ''] ?? 'Data sudah ada.');
            }
            throw $error;
        }
        $this->storefront->forgetBrand($brandId);

        return $result;
    }

    private function removeQuietly(?string $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            $this->storage->remove([$path]);
        } catch (Throwable) {
            // Best effort: foto yatim tidak merusak data dan bisa dibersihkan nanti.
        }
    }
}
