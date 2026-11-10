<?php

namespace App\Services\Inventory;

use App\Exceptions\AccessException;
use Illuminate\Support\Facades\DB;

/**
 * Ledger stok, port `lib/inventory/ledger.ts` — SATU-SATUNYA jalur yang mengubah
 * inventory_items.stock_qty (PRD §106). Wajib dipanggil di dalam transaksi DB:
 * 1. kunci baris item (FOR UPDATE) agar movement bersamaan antre;
 * 2. idempotency key unik per outlet: request ganda tidak memposting dua kali;
 * 3. saldo akhir negatif ditolak (CHECK di DB juga menolak);
 * 4. update saldo cache + insert baris ledger append-only (trigger menolak ubah/hapus).
 */
final class InventoryLedger
{
    /**
     * @param  array{itemId: string, type: string, quantity: int, idempotencyKey: string, actorId: ?string, unitCost?: ?string, reason?: ?string, photoPath?: ?string, documentRef?: ?string, referenceType?: ?string, referenceId?: ?string, reversesId?: ?string, batchCode?: ?string, expiryDate?: ?string, allowInactive?: bool}  $input  quantity dalam milli-unit bertanda
     * @return array{movementId: string, itemId: string, outletId: string, brandId: string, name: string, unit: string, before: int, after: int, replayed: bool}
     */
    public static function post(array $input): array
    {
        if ($input['quantity'] === 0) {
            throw new AccessException('invalid', 'Jumlah tidak boleh 0.');
        }
        $item = DB::selectOne(
            'select id, company_id, brand_id, outlet_id, name, unit, stock_qty, purchase_price, is_active from inventory_items where id = ? for update',
            [$input['itemId']],
        ) ?? throw new AccessException('not_found', 'Item stok tidak ditemukan.');
        $base = ['itemId' => $item->id, 'outletId' => $item->outlet_id, 'brandId' => $item->brand_id, 'name' => $item->name, 'unit' => $item->unit];

        // Setelah lock: posting yang sudah commit terlihat (READ COMMITTED).
        $existing = DB::table('inventory_transactions')
            ->where('outlet_id', $item->outlet_id)
            ->where('idempotency_key', $input['idempotencyKey'])
            ->first(['id', 'item_id', 'quantity', 'balance_after']);
        if ($existing !== null) {
            if ($existing->item_id !== $item->id) {
                throw new AccessException('conflict', 'Kunci transaksi sudah dipakai untuk item lain.');
            }
            $after = Qty::fromDb($existing->balance_after);

            return [...$base, 'movementId' => $existing->id, 'before' => $after - Qty::fromDb($existing->quantity), 'after' => $after, 'replayed' => true];
        }
        if (! $item->is_active && ! ($input['allowInactive'] ?? false)) {
            throw new AccessException('conflict', "{$item->name} nonaktif. Aktifkan item terlebih dahulu.");
        }

        $before = Qty::fromDb($item->stock_qty);
        $after = $before + $input['quantity'];
        if ($after < 0) {
            throw new AccessException('conflict', "Stok {$item->name} tidak cukup (tersisa ".Qty::format($before, $item->unit).').');
        }

        DB::table('inventory_items')->where('id', $item->id)->update(['stock_qty' => Qty::toDb($after), 'updated_at' => DB::raw('now()')]);
        $row = DB::selectOne(
            'insert into inventory_transactions (company_id, brand_id, outlet_id, item_id, type, quantity, balance_after, unit_cost, reason,
                photo_path, document_ref, reference_type, reference_id, reverses_id, batch_code, expiry_date, effective_date,
                idempotency_key, created_by, created_at)
             values (?, ?, ?, ?, ?::inventory_transaction_type, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                (now() at time zone (select o.timezone from outlets o where o.id = ?))::date, ?, ?, clock_timestamp())
             returning id',
            [
                $item->company_id, $item->brand_id, $item->outlet_id, $item->id, $input['type'], Qty::toDb($input['quantity']), Qty::toDb($after),
                array_key_exists('unitCost', $input) ? $input['unitCost'] : $item->purchase_price,
                $input['reason'] ?? null, $input['photoPath'] ?? null, $input['documentRef'] ?? null, $input['referenceType'] ?? null,
                $input['referenceId'] ?? null, $input['reversesId'] ?? null, $input['batchCode'] ?? null, $input['expiryDate'] ?? null,
                $item->outlet_id, $input['idempotencyKey'], $input['actorId'],
            ],
        );

        return [...$base, 'movementId' => $row->id, 'before' => $before, 'after' => $after, 'replayed' => false];
    }

    /** true bila saldo melewati batas 0 (tersedia ↔ habis): menu perlu diperbarui. */
    public static function crossedZero(array $posted): bool
    {
        return ! $posted['replayed'] && ($posted['before'] > 0) !== ($posted['after'] > 0);
    }

    /**
     * Potong stok saat order dibayar. Idempotent per (order, item); urutan item tetap agar lock
     * antar transaksi tidak deadlock.
     * - Produk jadi yang ditautkan ke menu di outlet itu (keputusan owner 9 November 2026):
     *   wajib cukup, stok kurang → exception → seluruh pembayaran dibatalkan.
     * - Resep/BOM (Fase 2, product_recipe_items): bahan per porsi × jumlah terjual. Tidak pernah
     *   menolak penjualan: bila stok bahan kurang (stok tidak boleh minus), dipotong sampai habis
     *   dan kekurangannya dikembalikan sebagai `shortfall` untuk log aktivitas.
     *
     * @return list<array<string, mixed>>
     */
    public static function postSale(string $orderId, string $outletId, ?string $actorId): array
    {
        $finished = DB::table('order_items')
            ->join('outlet_products', fn ($j) => $j->on('outlet_products.product_id', '=', 'order_items.product_id')->where('outlet_products.outlet_id', '=', $outletId))
            ->join('inventory_items', fn ($j) => $j->on('inventory_items.id', '=', 'outlet_products.inventory_item_id')->where('inventory_items.outlet_id', '=', $outletId))
            ->where('order_items.order_id', $orderId)
            ->groupBy('outlet_products.inventory_item_id')
            ->selectRaw('outlet_products.inventory_item_id as item_id, sum(order_items.quantity)::int as units')
            ->pluck('units', 'item_id')
            ->map(fn ($units) => (int) $units * 1000);
        $recipe = DB::table('order_items')
            ->join('product_recipe_items as r', fn ($j) => $j->on('r.product_id', '=', 'order_items.product_id')->where('r.outlet_id', '=', $outletId))
            ->where('order_items.order_id', $orderId)
            ->groupBy('r.item_id')
            ->selectRaw('r.item_id, sum(order_items.quantity * r.quantity) as qty')
            ->pluck('qty', 'item_id')
            ->map(fn ($qty) => Qty::fromDb((string) $qty));

        $itemIds = $finished->keys()->merge($recipe->keys())->unique()->sort()->values();
        $posted = [];
        foreach ($itemIds as $itemId) {
            $milli = ($finished[$itemId] ?? 0) + ($recipe[$itemId] ?? 0);
            $shortfall = 0;
            if (! $finished->has($itemId)) {
                // Bahan resep: kunci baris lalu potong paling banyak sebesar stok yang ada.
                $item = DB::selectOne('select name, unit, stock_qty from inventory_items where id = ? for update', [$itemId]);
                $available = max(0, Qty::fromDb($item->stock_qty));
                $shortfall = max(0, $milli - $available);
                $milli -= $shortfall;
                if ($milli <= 0) {
                    $posted[] = ['itemId' => $itemId, 'name' => $item->name, 'unit' => $item->unit, 'before' => 0, 'after' => 0, 'shortfall' => $shortfall];

                    continue;
                }
            }
            $line = self::post([
                'itemId' => $itemId,
                'type' => 'sale',
                'quantity' => -$milli,
                'idempotencyKey' => "sale:{$orderId}:{$itemId}",
                'actorId' => $actorId,
                'referenceType' => 'order',
                'referenceId' => $orderId,
                'allowInactive' => true,
            ]);
            $posted[] = $shortfall > 0 ? [...$line, 'shortfall' => $shortfall] : $line;
        }

        return $posted;
    }

    /**
     * Kembalikan stok penjualan order yang pembatalannya disetujui (reversal tiap movement sale).
     *
     * @return list<array<string, mixed>>
     */
    public static function reverseSale(string $orderId, ?string $actorId, string $reason): array
    {
        $sales = DB::table('inventory_transactions as t')
            ->where('t.reference_type', 'order')
            ->where('t.reference_id', $orderId)
            ->where('t.type', 'sale')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_transactions as r')->whereColumn('r.reverses_id', 't.id'))
            ->orderBy('t.item_id')
            ->get(['t.id', 't.item_id', 't.quantity', 't.unit_cost']);

        return $sales->map(fn ($sale) => self::post([
            'itemId' => $sale->item_id,
            'type' => 'reversal',
            'quantity' => -Qty::fromDb($sale->quantity),
            'idempotencyKey' => "reversal:{$sale->id}",
            'actorId' => $actorId,
            'unitCost' => $sale->unit_cost,
            'reason' => $reason,
            'referenceType' => 'order',
            'referenceId' => $orderId,
            'reversesId' => $sale->id,
            'allowInactive' => true,
        ]))->all();
    }
}
