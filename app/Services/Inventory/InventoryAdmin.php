<?php

namespace App\Services\Inventory;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Support\Access\AccessScope;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stok (M5-lite, PRD §18–25), port `lib/inventory/ops.ts` + `queries.ts`: item master
 * dengan status Aman/Menipis/Habis, tautan produk jadi ke menu, stok masuk, stok keluar &
 * waste (foto opsional), riwayat ledger, dan ringkasan dasbor. Izin per outlet:
 * inventory.view | adjust (item) | receive (masuk) | issue (keluar).
 */
final class InventoryAdmin
{
    public const KINDS = ['raw_material' => 'Bahan baku', 'finished_good' => 'Produk jadi', 'packaging' => 'Kemasan'];

    public const IN_TYPES = ['purchase' => 'Pembelian', 'return' => 'Retur', 'adjustment_in' => 'Penyesuaian (+)'];

    /** PRD §22 & §24: waste (rusak, expired, salah produksi, tumpah), pemakaian internal. */
    public const OUT_TYPES = ['waste' => 'Waste', 'expired' => 'Kedaluwarsa', 'damaged' => 'Rusak', 'internal_use' => 'Pemakaian internal', 'stock_out' => 'Keluar lainnya'];

    public const TYPE_LABELS = [
        'opening' => 'Saldo awal', 'purchase' => 'Pembelian', 'return' => 'Retur', 'adjustment_in' => 'Penyesuaian (+)',
        'sale' => 'Penjualan', 'waste' => 'Waste', 'expired' => 'Kedaluwarsa', 'damaged' => 'Rusak', 'internal_use' => 'Pemakaian internal',
        'stock_out' => 'Keluar lainnya', 'adjustment_out' => 'Penyesuaian (−)', 'opname' => 'Stock opname', 'reversal' => 'Pembalikan',
    ];

    public function __construct(private AccessLoader $access, private AuditLogger $audit, private EvidenceStorage $evidence) {}

    /** Outlet yang boleh dibuka dengan izin tertentu. */
    public function outlets(User $actor, string $permission): array
    {
        return array_values(array_filter($this->access->accessibleOutlets($actor), fn (array $o) => $actor->can($permission, AccessScope::outlet($o['id']))));
    }

    /** @return array{ok: bool, adjust: bool, receive: bool, issue: bool, opname: bool} */
    public function can(User $actor, string $outletId): array
    {
        $scope = AccessScope::outlet($outletId);

        return [
            'adjust' => $actor->can('inventory.adjust', $scope),
            'receive' => $actor->can('inventory.receive', $scope),
            'issue' => $actor->can('inventory.issue', $scope),
            'opname' => $actor->can('inventory.opname', $scope),
            'ok' => true,
        ];
    }

    // --- Daftar & status -----------------------------------------------------------------

    /** @param array{id: string} $outlet */
    public function items(User $actor, array $outlet, array $params): array
    {
        $this->assertAt($actor, 'inventory.view', $outlet['id']);
        $q = is_string($params['q'] ?? null) && trim($params['q']) !== '' ? mb_substr(trim($params['q']), 0, 60) : null;
        $kind = array_key_exists((string) ($params['kind'] ?? ''), self::KINDS) ? $params['kind'] : null;
        $status = in_array($params['status'] ?? null, ['low', 'out', 'ok', 'inactive'], true) ? $params['status'] : null;

        $rows = DB::table('inventory_items')
            ->where('company_id', $actor->company_id)
            ->where('outlet_id', $outlet['id'])
            ->when($q !== null, function ($query) use ($q) {
                $pattern = '%'.addcslashes($q, '\\%_').'%';
                $query->where(fn ($w) => $w->where('name', 'ilike', $pattern)->orWhere('sku', 'ilike', $pattern)->orWhere('category', 'ilike', $pattern));
            })
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(in_array($status, ['low', 'out', 'ok'], true), fn ($query) => $query->where('is_active', true)->whereRaw(match ($status) {
                'out' => 'stock_qty <= 0',
                'low' => 'stock_qty > 0 and stock_qty <= minimum_stock',
                'ok' => 'stock_qty > minimum_stock',
            }))
            ->orderByRaw('is_active desc, case when stock_qty <= 0 then 0 when stock_qty <= minimum_stock then 1 else 2 end, name')
            ->limit(500)
            ->get();
        $links = $rows->isEmpty() ? collect() : DB::table('outlet_products')
            ->join('products', 'products.id', '=', 'outlet_products.product_id')
            ->where('outlet_products.outlet_id', $outlet['id'])
            ->whereIn('outlet_products.inventory_item_id', $rows->pluck('id')->all())
            ->whereNull('products.deleted_at')
            ->orderBy('products.name')
            ->get(['outlet_products.inventory_item_id', 'products.name'])
            ->groupBy('inventory_item_id');

        return [
            'rows' => $rows->map(fn ($r) => $this->itemRow($r, ($links[$r->id] ?? collect())->pluck('name')->all()))->all(),
            'counts' => $this->counts($actor->company_id, $outlet['id']),
            'params' => ['q' => $q, 'kind' => $kind, 'status' => $status],
        ];
    }

    /** @return array{ok: int, low: int, out: int, inactive: int} */
    public function counts(string $companyId, string $outletId): array
    {
        $row = DB::table('inventory_items')->where('company_id', $companyId)->where('outlet_id', $outletId)
            ->selectRaw('count(*) filter (where is_active and stock_qty > minimum_stock) as ok,
                count(*) filter (where is_active and stock_qty > 0 and stock_qty <= minimum_stock) as low,
                count(*) filter (where is_active and stock_qty <= 0) as out,
                count(*) filter (where not is_active) as inactive')
            ->first();

        return ['ok' => (int) $row->ok, 'low' => (int) $row->low, 'out' => (int) $row->out, 'inactive' => (int) $row->inactive];
    }

    /** Ringkasan untuk dasbor: hitungan status + item yang perlu perhatian. */
    public function dashboard(string $companyId, string $outletId): array
    {
        $attention = DB::table('inventory_items')->where('company_id', $companyId)->where('outlet_id', $outletId)->where('is_active', true)
            ->whereRaw('stock_qty <= minimum_stock')
            ->orderByRaw('case when stock_qty <= 0 then 0 else 1 end, name')
            ->limit(6)
            ->get(['id', 'name', 'unit', 'stock_qty', 'minimum_stock']);

        return [
            'counts' => $this->counts($companyId, $outletId),
            'attention' => $attention->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'stock' => Qty::format(Qty::fromDb($i->stock_qty), $i->unit),
                'status' => Qty::fromDb($i->stock_qty) <= 0 ? 'out' : 'low',
            ])->all(),
        ];
    }

    // --- Item master ---------------------------------------------------------------------

    public function item(User $actor, string $itemId): array
    {
        $item = $this->loadItem($actor, $itemId);
        $this->assertAt($actor, 'inventory.view', $item->outlet_id);

        return [
            ...$this->itemRow($item, []),
            'outletId' => $item->outlet_id,
            'minimumInput' => rtrim(rtrim(Qty::toDb(Qty::fromDb($item->minimum_stock)), '0'), '.'),
            'linkedProductIds' => DB::table('outlet_products')->where('outlet_id', $item->outlet_id)->where('inventory_item_id', $item->id)->pluck('product_id')->all(),
            'hasMovements' => DB::table('inventory_transactions')->where('item_id', $item->id)->exists(),
        ];
    }

    /** Menu brand outlet untuk ditautkan ke produk jadi (satu menu hanya satu item stok). */
    public function productOptions(User $actor, string $outletId): array
    {
        $this->assertAt($actor, 'inventory.view', $outletId);
        $brandId = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->value('brand_id') ?? throw AccessException::notFound();

        return DB::table('products')
            ->leftJoin('outlet_products', fn ($j) => $j->on('outlet_products.product_id', '=', 'products.id')->where('outlet_products.outlet_id', '=', $outletId))
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'outlet_products.inventory_item_id')
            ->where('products.company_id', $actor->company_id)
            ->where('products.brand_id', $brandId)
            ->whereNull('products.deleted_at')
            ->orderBy('products.name')
            ->get(['products.id', 'products.name', 'outlet_products.inventory_item_id', 'inventory_items.name as item_name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'linkedItemId' => $p->inventory_item_id, 'linkedItemName' => $p->item_name])
            ->all();
    }

    public function createItem(User $actor, array $raw, AuditContext $context): string
    {
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id', 'timezone'])
            ?? throw new AccessException('invalid', 'Outlet tidak ditemukan.');
        $this->assertAt($actor, 'inventory.adjust', $outlet->id);
        $input = InventoryInput::item($raw, true);
        $opening = ($raw['openingStock'] ?? '') === '' || $raw['openingStock'] === null ? 0 : Qty::parse($raw['openingStock'], $input['unit'], $input['unit']);
        $productIds = $input['kind'] === 'finished_good' ? InventoryInput::uuids($raw['productIds'] ?? []) : [];
        $this->assertNotExpired($input['expiryDate'], $outlet->timezone);

        return $this->unique(fn () => DB::transaction(function () use ($actor, $outlet, $input, $opening, $productIds, $context) {
            $id = DB::table('inventory_items')->insertGetId([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'sku' => $input['sku'],
                'name' => $input['name'],
                'kind' => $input['kind'],
                'category' => $input['category'],
                'unit' => $input['unit'],
                'minimum_stock' => Qty::toDb($input['minimum']),
                'purchase_price' => $input['purchasePrice'],
                'expiry_date' => $input['expiryDate'],
            ]);
            if ($opening > 0) {
                InventoryLedger::post(['itemId' => $id, 'type' => 'opening', 'quantity' => $opening, 'idempotencyKey' => "opening:{$id}", 'actorId' => $actor->id, 'reason' => 'Saldo awal']);
            }
            $this->linkProducts($actor, $id, $outlet->id, $productIds);
            $this->audit->record([
                'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'actor_id' => $actor->id,
                'action' => 'inventory.item_created', 'entity_type' => 'inventory_item', 'entity_id' => $id,
                'after' => ['sku' => $input['sku'], 'name' => $input['name'], 'kind' => $input['kind'], 'unit' => $input['unit'],
                    'openingStock' => Qty::toDb($opening), 'linkedProducts' => count($productIds)],
            ], $context);

            return $id;
        }));
    }

    public function updateItem(User $actor, string $itemId, array $raw, AuditContext $context): void
    {
        $item = $this->loadItem($actor, $itemId);
        $this->assertAt($actor, 'inventory.adjust', $item->outlet_id);
        $input = InventoryInput::item($raw, false, $item->unit);
        $hasMovements = DB::table('inventory_transactions')->where('item_id', $item->id)->exists();
        if ($input['unit'] !== $item->unit && $hasMovements) {
            throw new AccessException('invalid', 'Satuan tidak bisa diubah setelah ada riwayat stok.');
        }
        $timezone = DB::table('outlets')->where('id', $item->outlet_id)->value('timezone');
        if ($input['expiryDate'] !== $item->expiry_date) {
            $this->assertNotExpired($input['expiryDate'], $timezone);
        }
        $productIds = $input['kind'] === 'finished_good' ? InventoryInput::uuids($raw['productIds'] ?? []) : [];

        $this->unique(fn () => DB::transaction(function () use ($actor, $item, $input, $productIds, $context) {
            DB::table('inventory_items')->where('id', $item->id)->update([
                'sku' => $input['sku'],
                'name' => $input['name'],
                'kind' => $input['kind'],
                'category' => $input['category'],
                'unit' => $input['unit'],
                'minimum_stock' => Qty::toDb($input['minimum']),
                'purchase_price' => $input['purchasePrice'],
                'expiry_date' => $input['expiryDate'],
                'is_active' => $input['isActive'],
                'updated_at' => DB::raw('now()'),
            ]);
            $this->linkProducts($actor, $item->id, $item->outlet_id, $productIds);
            $this->audit->record([
                'company_id' => $item->company_id, 'brand_id' => $item->brand_id, 'outlet_id' => $item->outlet_id, 'actor_id' => $actor->id,
                'action' => 'inventory.item_updated', 'entity_type' => 'inventory_item', 'entity_id' => $item->id,
                'before' => ['sku' => $item->sku, 'name' => $item->name, 'minimum' => $item->minimum_stock, 'purchasePrice' => $item->purchase_price, 'isActive' => (bool) $item->is_active],
                'after' => ['sku' => $input['sku'], 'name' => $input['name'], 'minimum' => Qty::toDb($input['minimum']), 'purchasePrice' => $input['purchasePrice'], 'isActive' => $input['isActive'], 'linkedProducts' => count($productIds)],
            ], $context);
        }));
    }

    /** Tautan menu → item produk jadi di outlet ini (dipotong otomatis saat pesanan dibayar). */
    private function linkProducts(User $actor, string $itemId, string $outletId, array $productIds): void
    {
        $brandId = DB::table('outlets')->where('id', $outletId)->value('brand_id');
        $valid = $productIds === [] ? [] : DB::table('products')->where('company_id', $actor->company_id)->where('brand_id', $brandId)
            ->whereNull('deleted_at')->whereIn('id', $productIds)->pluck('id')->all();
        if (count($valid) !== count($productIds)) {
            throw new AccessException('invalid', 'Menu yang dipilih tidak ditemukan di brand outlet ini.');
        }
        $taken = $valid === [] ? collect() : DB::table('outlet_products')
            ->join('inventory_items', 'inventory_items.id', '=', 'outlet_products.inventory_item_id')
            ->join('products', 'products.id', '=', 'outlet_products.product_id')
            ->where('outlet_products.outlet_id', $outletId)
            ->whereIn('outlet_products.product_id', $valid)
            ->where('outlet_products.inventory_item_id', '<>', $itemId)
            ->get(['products.name', 'inventory_items.name as item_name']);
        if ($taken->isNotEmpty()) {
            $first = $taken->first();
            throw new AccessException('conflict', "{$first->name} sudah memakai stok {$first->item_name}. Lepas dulu dari item itu.");
        }
        DB::table('outlet_products')->where('outlet_id', $outletId)->where('inventory_item_id', $itemId)
            ->when($valid !== [], fn ($q) => $q->whereNotIn('product_id', $valid))
            ->update(['inventory_item_id' => null]);
        foreach ($valid as $productId) {
            DB::table('outlet_products')->updateOrInsert(['outlet_id' => $outletId, 'product_id' => $productId], ['inventory_item_id' => $itemId]);
        }
    }

    // --- Stok masuk / keluar ---------------------------------------------------------------

    /** @return array{balance: string, replayed: bool} */
    public function stockIn(User $actor, array $raw, AuditContext $context): array
    {
        $item = $this->loadItem($actor, CatalogInput::uuid($raw['itemId'] ?? null));
        $this->assertAt($actor, 'inventory.receive', $item->outlet_id);
        $type = array_key_exists((string) ($raw['type'] ?? ''), self::IN_TYPES) ? $raw['type'] : throw new AccessException('invalid', 'Pilih jenis stok masuk.');
        $unit = $raw['unit'] ?? $item->unit;
        $quantity = Qty::parse($raw['quantity'] ?? null, $unit, $item->unit);
        $unitCost = Qty::isUnit($unit) ? Qty::unitCost($raw['unitCost'] ?? null, $unit, $item->unit) : null;
        $expiry = InventoryInput::date($raw['expiryDate'] ?? null, 'Tanggal kedaluwarsa');
        $this->assertNotExpired($expiry, DB::table('outlets')->where('id', $item->outlet_id)->value('timezone'));
        $key = InventoryInput::idempotencyKey($raw['idempotencyKey'] ?? null);
        $notes = InventoryInput::text($raw['notes'] ?? null, 300);
        $documentRef = InventoryInput::text($raw['documentRef'] ?? null, 60);
        $batch = InventoryInput::text($raw['batchCode'] ?? null, 40);

        return DB::transaction(function () use ($actor, $item, $type, $quantity, $unit, $raw, $unitCost, $expiry, $key, $notes, $documentRef, $batch, $context) {
            $posted = InventoryLedger::post([
                'itemId' => $item->id, 'type' => $type, 'quantity' => $quantity, 'idempotencyKey' => "stock-in:{$key}", 'actorId' => $actor->id,
                'unitCost' => $unitCost ?? $item->purchase_price, 'reason' => $notes, 'documentRef' => $documentRef, 'batchCode' => $batch, 'expiryDate' => $expiry,
            ]);
            if ($posted['replayed']) {
                return ['balance' => Qty::format($posted['after'], $item->unit), 'replayed' => true];
            }
            // Harga beli terakhir menjadi harga pokok; kedaluwarsa = tanggal terdekat stok yang masih ada.
            $current = DB::table('inventory_items')->where('id', $item->id)->first(['expiry_date', 'purchase_price']);
            $expiryAfter = $expiry === null ? $current->expiry_date : ($current->expiry_date === null || $posted['before'] <= 0 ? $expiry : min($expiry, $current->expiry_date));
            $updates = array_filter([
                'purchase_price' => $type === 'purchase' && $unitCost !== null ? $unitCost : null,
                'expiry_date' => $expiryAfter !== $current->expiry_date ? $expiryAfter : null,
            ], fn ($v) => $v !== null);
            if ($updates !== []) {
                DB::table('inventory_items')->where('id', $item->id)->update($updates);
            }
            $this->audit->record([
                'company_id' => $item->company_id, 'brand_id' => $item->brand_id, 'outlet_id' => $item->outlet_id, 'actor_id' => $actor->id,
                'action' => 'inventory.stock_in', 'entity_type' => 'inventory_item', 'entity_id' => $item->id,
                'before' => ['stock' => Qty::toDb($posted['before'])],
                'after' => ['stock' => Qty::toDb($posted['after']), 'type' => self::IN_TYPES[$type], 'quantity' => Qty::toDb($quantity), 'input' => ($raw['quantity'] ?? '')." {$unit}", 'documentRef' => $documentRef],
                'reason' => $notes,
            ], $context);

            return ['balance' => Qty::format($posted['after'], $item->unit), 'replayed' => false];
        });
    }

    /** Stok keluar & waste (PRD §22, §24). Foto opsional diunggah dulu; gagal → dihapus lagi. */
    public function stockOut(User $actor, array $raw, mixed $photo, AuditContext $context): array
    {
        $item = $this->loadItem($actor, CatalogInput::uuid($raw['itemId'] ?? null));
        $this->assertAt($actor, 'inventory.issue', $item->outlet_id);
        $type = array_key_exists((string) ($raw['type'] ?? ''), self::OUT_TYPES) ? $raw['type'] : throw new AccessException('invalid', 'Pilih jenis stok keluar.');
        $quantity = Qty::parse($raw['quantity'] ?? null, $raw['unit'] ?? $item->unit, $item->unit);
        $reason = Money::reason($raw['reason'] ?? null, 300, 'Alasan wajib diisi.', 'Alasan minimal 3 karakter.');
        $key = InventoryInput::idempotencyKey($raw['idempotencyKey'] ?? null);
        $image = MenuImages::read($photo);
        if ($image !== null && strlen($image['bytes']) > 3 * 1024 * 1024) {
            throw new AccessException('invalid', 'Ukuran foto maksimal 3 MB.');
        }
        $path = $image === null ? null : "{$item->company_id}/{$item->outlet_id}/".Str::uuid().".{$image['ext']}";
        if ($path !== null) {
            $this->evidence->upload($path, $image['bytes'], $image['contentType']);
        }

        try {
            return DB::transaction(function () use ($actor, $item, $type, $quantity, $reason, $key, $path, $raw, $context) {
                $posted = InventoryLedger::post([
                    'itemId' => $item->id, 'type' => $type, 'quantity' => -$quantity, 'idempotencyKey' => "stock-out:{$key}",
                    'actorId' => $actor->id, 'reason' => $reason, 'photoPath' => $path,
                ]);
                if ($posted['replayed']) {
                    if ($path !== null) {
                        $this->discard($path);
                    }

                    return ['balance' => Qty::format($posted['after'], $item->unit), 'replayed' => true];
                }
                $this->audit->record([
                    'company_id' => $item->company_id, 'brand_id' => $item->brand_id, 'outlet_id' => $item->outlet_id, 'actor_id' => $actor->id,
                    'action' => 'inventory.stock_out', 'entity_type' => 'inventory_item', 'entity_id' => $item->id,
                    'before' => ['stock' => Qty::toDb($posted['before'])],
                    'after' => ['stock' => Qty::toDb($posted['after']), 'type' => self::OUT_TYPES[$type], 'quantity' => Qty::toDb($quantity), 'input' => ($raw['quantity'] ?? '').' '.($raw['unit'] ?? $item->unit), 'photo' => $path !== null],
                    'reason' => $reason,
                ], $context);

                return ['balance' => Qty::format($posted['after'], $item->unit), 'replayed' => false];
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                $this->discard($path);
            }
            throw $error;
        }
    }

    // --- Riwayat ----------------------------------------------------------------------------

    /** @param array{id: string, timezone: string} $outlet */
    public function movements(User $actor, array $outlet, array $params): array
    {
        $this->assertAt($actor, 'inventory.view', $outlet['id']);
        $itemId = CatalogInput::isUuid($params['item'] ?? null) ? strtolower($params['item']) : null;
        $group = in_array($params['group'] ?? null, ['in', 'out', 'sale', 'opname'], true) ? $params['group'] : null;
        $types = match ($group) {
            'in' => ['opening', 'purchase', 'return', 'adjustment_in'],
            'out' => ['waste', 'expired', 'damaged', 'internal_use', 'stock_out', 'adjustment_out'],
            'sale' => ['sale', 'reversal'],
            'opname' => ['opname'],
            default => null,
        };
        $page = max(1, (int) ($params['page'] ?? 1));
        $query = DB::table('inventory_transactions as t')
            ->join('inventory_items as i', 'i.id', '=', 't.item_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.company_id', $actor->company_id)
            ->where('t.outlet_id', $outlet['id'])
            ->when($itemId !== null, fn ($q) => $q->where('t.item_id', $itemId))
            ->when($types !== null, fn ($q) => $q->whereIn('t.type', $types));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('t.created_at')->limit(50)->offset(($page - 1) * 50)
            ->get(['t.id', 't.type', 't.quantity', 't.balance_after', 't.reason', 't.document_ref', 't.photo_path', 't.reference_type', 't.created_at',
                'i.name as item_name', 'i.unit', 'u.full_name as actor_name']);

        return [
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'type' => $r->type,
                'typeLabel' => self::TYPE_LABELS[$r->type] ?? $r->type,
                'itemName' => $r->item_name,
                'quantity' => Qty::format(Qty::fromDb($r->quantity), $r->unit),
                'incoming' => Qty::fromDb($r->quantity) > 0,
                'balance' => Qty::format(Qty::fromDb($r->balance_after), $r->unit),
                'reason' => $r->reason,
                'documentRef' => $r->document_ref,
                'hasPhoto' => $r->photo_path !== null,
                'actorName' => $r->actor_name ?? ($r->reference_type === 'order' ? 'Penjualan' : 'Sistem'),
                'createdAt' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
            ])->all(),
            'total' => $total,
            'page' => $page,
            'pageCount' => max(1, (int) ceil($total / 50)),
            'params' => ['item' => $itemId, 'group' => $group],
        ];
    }

    /** Foto bukti lewat server berizin; null bila tidak ada. */
    public function evidencePhoto(User $actor, string $movementId): ?array
    {
        $row = DB::table('inventory_transactions')->where('id', $movementId)->where('company_id', $actor->company_id)->first(['outlet_id', 'photo_path'])
            ?? throw AccessException::notFound();
        $this->assertAt($actor, 'inventory.view', $row->outlet_id);
        if ($row->photo_path === null) {
            return null;
        }
        $bytes = $this->evidence->download($row->photo_path);

        return $bytes === null ? null : ['bytes' => $bytes, 'contentType' => MenuImages::detect($bytes)['contentType'] ?? 'image/jpeg'];
    }

    /** Pilihan item untuk form (aktif saja), dengan satuan yang setara. */
    public function itemOptions(User $actor, string $outletId): array
    {
        $this->assertAt($actor, 'inventory.view', $outletId);

        return DB::table('inventory_items')->where('company_id', $actor->company_id)->where('outlet_id', $outletId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'sku', 'name', 'unit', 'stock_qty'])
            ->map(fn ($i) => ['id' => $i->id, 'label' => "{$i->name} ({$i->sku})", 'unit' => $i->unit, 'units' => Qty::compatible($i->unit), 'stock' => Qty::format(Qty::fromDb($i->stock_qty), $i->unit)])
            ->all();
    }

    // --- Bantuan ------------------------------------------------------------------------------

    private function itemRow(object $r, array $linked): array
    {
        $stock = Qty::fromDb($r->stock_qty);
        $minimum = Qty::fromDb($r->minimum_stock);

        return [
            'id' => $r->id,
            'sku' => $r->sku,
            'name' => $r->name,
            'kind' => $r->kind,
            'kindLabel' => self::KINDS[$r->kind] ?? $r->kind,
            'category' => $r->category,
            'unit' => $r->unit,
            'stock' => Qty::format($stock, $r->unit),
            'minimum' => Qty::format($minimum, $r->unit),
            'status' => ! $r->is_active ? 'inactive' : ($stock <= 0 ? 'out' : ($stock <= $minimum ? 'low' : 'ok')),
            'purchasePrice' => $r->purchase_price === null ? null : (int) round((float) $r->purchase_price),
            'expiryDate' => $r->expiry_date,
            'isActive' => (bool) $r->is_active,
            'linkedProducts' => $linked,
        ];
    }

    private function loadItem(User $actor, string $itemId): object
    {
        return DB::table('inventory_items')->where('id', $itemId)->where('company_id', $actor->company_id)->first() ?? throw AccessException::notFound();
    }

    private function assertNotExpired(?string $expiry, string $timezone): void
    {
        if ($expiry !== null && $expiry < CarbonImmutable::now($timezone)->toDateString()) {
            throw new AccessException('invalid', 'Tanggal kedaluwarsa sudah lewat.');
        }
    }

    private function unique(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $error) {
            if ($error->getCode() === '23505' && str_contains((string) ($error->errorInfo[2] ?? ''), '(outlet_id, sku)')) {
                throw new AccessException('conflict', 'SKU sudah dipakai item lain di outlet ini.');
            }
            throw $error;
        }
    }

    private function discard(string $path): void
    {
        try {
            $this->evidence->remove([$path]);
        } catch (Throwable) {
            // Tidak menutupi error asli.
        }
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
