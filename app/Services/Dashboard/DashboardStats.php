<?php

namespace App\Services\Dashboard;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Cash\CashSessions;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan dasbor per outlet, hanya-baca. Semua angka dihitung dari tabel transaksi
 * (bukan cache) dengan scope company + outlet, dan "hari ini" mengikuti zona waktu outlet.
 *
 * Penjualan = pesanan yang dibayar hari ini dan masih berstatus lunas (bukan dibatalkan
 * atau direfund). Bagian yang butuh izin lain (kas, approval, stok menu) bernilai null
 * bila actor tidak punya izinnya.
 */
final class DashboardStats
{
    public const TOP_PRODUCTS = 5;

    /** @param array{id: string, timezone: string, brandId?: string} $outlet */
    public function summary(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        // Grant tingkat company cocok untuk outlet mana pun, jadi kepemilikan outlet dicek eksplisit.
        $ownOutlet = DB::table('outlets')->where('id', $outlet['id'])->where('company_id', $actor->company_id)->exists();
        if (! $ownOutlet || ! $actor->can('dashboard.view', $scope)) {
            throw AccessException::forbidden();
        }
        $tz = $outlet['timezone'];
        $today = CarbonImmutable::now($tz)->toDateString();
        $base = fn () => DB::table('orders')->where('orders.outlet_id', $outlet['id'])->where('orders.company_id', $actor->company_id);
        $paidToday = fn () => $base()
            ->where('orders.payment_status', 'paid')
            ->where('orders.status', '!=', 'cancelled')
            ->whereRaw('(orders.paid_at at time zone ?)::date = ?::date', [$tz, $today]);

        $sales = $paidToday()->selectRaw('count(*) as orders, coalesce(sum(orders.total), 0) as revenue')->first();
        $orders = (int) $sales->orders;
        $revenue = (int) $sales->revenue;
        $items = (int) $paidToday()->join('order_items', 'order_items.order_id', '=', 'orders.id')->sum('order_items.quantity');
        $top = $paidToday()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity')
            ->orderBy('order_items.product_name')
            ->limit(self::TOP_PRODUCTS)
            ->selectRaw('order_items.product_name as name, sum(order_items.quantity) as quantity, sum(order_items.line_total) as revenue')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (int) $r->quantity, 'revenue' => (int) $r->revenue])
            ->all();

        return [
            'date' => $today,
            'sales' => [
                'revenue' => $revenue,
                'orders' => $orders,
                'average' => $orders === 0 ? 0 : intdiv($revenue, $orders),
                'items' => $items,
            ],
            'pendingPayment' => $base()->where('orders.status', 'pending_payment')->count(),
            'inProgress' => $base()->where('orders.payment_status', 'paid')->whereIn('orders.status', ['queued', 'preparing', 'ready'])->count(),
            'completedToday' => $base()->where('orders.status', 'completed')->whereRaw('(orders.completed_at at time zone ?)::date = ?::date', [$tz, $today])->count(),
            'topProducts' => $top,
            'cash' => $actor->can('cash.view', $scope) ? $this->cash($actor, $outlet['id']) : null,
            'pendingApprovals' => $actor->can('approval.view', $scope)
                ? DB::table('approvals')->where('company_id', $actor->company_id)->where('outlet_id', $outlet['id'])->where('status', 'pending')->count()
                : null,
            'soldOut' => $actor->can('product.view', $scope) ? $this->soldOut($actor, $outlet['id']) : null,
        ];
    }

    /** Sesi terbuka (saldo sistem saat ini) dan selisih sesi terakhir yang ditutup. */
    private function cash(User $actor, string $outletId): array
    {
        $open = DB::table('cash_sessions')->where('company_id', $actor->company_id)->where('outlet_id', $outletId)->where('status', 'open')->first();
        $last = DB::table('cash_sessions')
            ->where('company_id', $actor->company_id)->where('outlet_id', $outletId)->where('status', 'closed')
            ->orderByDesc('closed_at')->first(['closed_at', 'expected_amount', 'actual_amount']);
        $iso = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t)->toIso8601String();

        return [
            'open' => $open === null ? null : ['openedAt' => $iso($open->opened_at), 'expected' => CashSessions::totals($open)['expected']],
            'lastClosed' => $last === null ? null : [
                'closedAt' => $iso($last->closed_at),
                'variance' => (int) $last->actual_amount - (int) $last->expected_amount,
            ],
        ];
    }

    /**
     * Menu aktif yang tidak bisa dipesan di outlet ini: dimatikan manual atau stok produk
     * jadi habis. Logika sama dengan filter ketersediaan di CatalogAdmin::listProducts.
     *
     * @return array{count: int, names: list<string>}
     */
    private function soldOut(User $actor, string $outletId): array
    {
        $rows = DB::table('outlet_products')
            ->join('products', 'products.id', '=', 'outlet_products.product_id')
            ->join('outlets', 'outlets.id', '=', 'outlet_products.outlet_id')
            ->leftJoin('inventory_items', function ($join) {
                $join->on('inventory_items.id', '=', 'outlet_products.inventory_item_id')
                    ->on('inventory_items.outlet_id', '=', 'outlet_products.outlet_id');
            })
            ->where('outlet_products.outlet_id', $outletId)
            ->where('outlets.company_id', $actor->company_id)
            ->whereColumn('products.brand_id', 'outlets.brand_id')
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereRaw('(not coalesce(outlet_products.is_available, true) or (inventory_items.id is not null and (not inventory_items.is_active or inventory_items.stock_qty <= 0)))')
            ->orderBy('products.name')
            ->pluck('products.name');

        return ['count' => $rows->count(), 'names' => $rows->take(5)->values()->all()];
    }
}
