<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Cash\CashSessions;
use App\Services\Inventory\InventoryAdmin;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan dasbor dari data asli satu outlet, untuk tanggal operasional hari ini (zona
 * waktu outlet). Tiap bagian hanya dihitung bila actor punya izinnya; bila tidak, null.
 * Semua query hanya-baca dan dibatasi company + outlet.
 */
final class DashboardSummary
{
    /** Pesanan yang sedang di antrean produksi (sudah dibayar, belum selesai). */
    private const IN_QUEUE = ['paid', 'queued', 'preparing', 'ready'];

    /**
     * @param  array{id: string, timezone: string}  $outlet
     * @return array<string, mixed>
     */
    public function forOutlet(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        $today = CarbonImmutable::now($outlet['timezone'])->toDateString();
        $where = ['company_id' => $actor->company_id, 'outlet_id' => $outlet['id']];

        return [
            'businessDate' => $today,
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
            'sales' => $actor->can('report.sales', $scope) ? $this->sales($where, $today) : null,
            'orders' => $actor->can('order.view', $scope) ? $this->orders($where, $today) : null,
            'cash' => $actor->can('cash.view', $scope) ? $this->cash($where) : null,
            // Sama dengan isi kotak Persetujuan: pengajuan pembatalan pesanan lunas.
            'pendingApprovals' => $actor->can('approval.view', $scope)
                ? DB::table('approvals')->where($where)->where('type', 'void')->where('status', 'pending')->count()
                : null,
            'soldOut' => $actor->can('product.view', $scope) ? $this->soldOut($actor, $outlet['id']) : null,
            'pendingCorrections' => $actor->can('approval.view', $scope)
                ? DB::table('approvals')->where($where)->where('type', 'attendance_correction')->where('status', 'pending')->count()
                : null,
            'attendance' => $actor->can('attendance.view', $scope) ? $this->attendance($where, $today) : null,
            'stock' => $actor->can('inventory.view', $scope) ? app(InventoryAdmin::class)->dashboard($actor->company_id, $outlet['id']) : null,
            'pendingOpnames' => $actor->can('approval.view', $scope)
                ? DB::table('approvals')->where($where)->where('type', 'stock_opname')->where('status', 'pending')->count()
                : null,
            'pendingOutsideDuties' => $actor->can('approval.view', $scope)
                ? DB::table('approvals')->where($where)->where('type', 'outside_duty')->where('status', 'pending')->count()
                : null,
        ];
    }

    /**
     * Penjualan bersih hari ini: pesanan lunas (bukan dibatalkan/direfund) pada tanggal operasional.
     *
     * @param  array<string, string>  $where
     */
    private function sales(array $where, string $today): array
    {
        $paid = fn () => DB::table('orders')
            ->where('orders.company_id', $where['company_id'])
            ->where('orders.outlet_id', $where['outlet_id'])
            ->where('orders.business_date', $today)
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.status', ['cancelled', 'refunded']);

        $totals = $paid()->selectRaw('count(*) as transactions, coalesce(sum(orders.total), 0) as revenue')->first();
        $top = $paid()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('order_items.product_name')
            ->selectRaw('order_items.product_name as name, sum(order_items.quantity) as quantity, sum(order_items.line_total) as amount')
            ->orderByDesc('quantity')->orderByDesc('amount')->orderBy('order_items.product_name')
            ->get();
        $transactions = (int) $totals->transactions;
        $revenue = (int) $totals->revenue;

        return [
            'revenue' => $revenue,
            'transactions' => $transactions,
            'average' => $transactions === 0 ? 0 : intdiv($revenue, $transactions),
            'itemsSold' => (int) $top->sum('quantity'),
            'topProducts' => $top->take(5)->map(fn ($row) => [
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'amount' => (int) $row->amount,
            ])->values()->all(),
        ];
    }

    /** @param array<string, string> $where */
    private function orders(array $where, string $today): array
    {
        $queue = "'".implode("','", self::IN_QUEUE)."'";
        $row = DB::table('orders')
            ->where($where)
            ->where(fn ($q) => $q->where('business_date', $today)->orWhereIn('status', ['pending_payment', ...self::IN_QUEUE]))
            ->selectRaw("count(*) filter (where status = 'pending_payment' and (expires_at is null or expires_at > now())) as pending_payment")
            ->selectRaw("count(*) filter (where status in ({$queue})) as in_queue")
            ->selectRaw("count(*) filter (where status = 'completed' and business_date = ?) as completed", [$today])
            ->first();

        return [
            'pendingPayment' => (int) $row->pending_payment,
            'inQueue' => (int) $row->in_queue,
            'completedToday' => (int) $row->completed,
        ];
    }

    /** @param array<string, string> $where */
    private function cash(array $where): array
    {
        $sessions = DB::table('cash_sessions')
            ->leftJoin('users', 'users.id', '=', 'cash_sessions.opened_by')
            ->where('cash_sessions.company_id', $where['company_id'])
            ->where('cash_sessions.outlet_id', $where['outlet_id'])
            ->orderByDesc('cash_sessions.opened_at')
            ->limit(5)
            ->get(['cash_sessions.*', 'users.full_name as opened_by_name']);
        $open = $sessions->firstWhere('status', 'open');
        $closed = $sessions->first(fn ($s) => $s->status === 'closed');
        $iso = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t)->toIso8601String();

        return [
            'open' => $open === null ? null : [
                'openedAt' => $iso($open->opened_at),
                'openedByName' => $open->opened_by_name,
                'expected' => CashSessions::totals($open)['expected'],
            ],
            'lastClosed' => $closed === null ? null : [
                'closedAt' => $iso($closed->closed_at),
                'expected' => (int) $closed->expected_amount,
                'actual' => (int) $closed->actual_amount,
                'variance' => (int) $closed->actual_amount - (int) $closed->expected_amount,
            ],
        ];
    }

    /**
     * Kehadiran karyawan aktif outlet hari ini (tanggal bisnis absen).
     *
     * @param  array<string, string>  $where
     */
    private function attendance(array $where, string $today): array
    {
        $employees = DB::table('employees')->where($where)->where('is_active', true)->count();
        $row = DB::table('attendances')->where($where)->where('business_date', $today)
            ->selectRaw('count(distinct employee_id) as present, count(distinct employee_id) filter (where check_out_at is null) as working')
            ->first();
        $present = (int) $row->present;
        $working = (int) $row->working;

        return ['employees' => $employees, 'working' => $working, 'done' => $present - $working, 'absent' => max(0, $employees - $present)];
    }

    /** Produk aktif yang ditandai habis di outlet ini. */
    private function soldOut(User $actor, string $outletId): int
    {
        return DB::table('outlet_products')
            ->join('products', 'products.id', '=', 'outlet_products.product_id')
            ->where('outlet_products.outlet_id', $outletId)
            ->where('outlet_products.is_available', false)
            ->where('products.company_id', $actor->company_id)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->count();
    }
}
