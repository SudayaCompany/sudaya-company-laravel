<?php

namespace App\Services\Customers;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Support\Access\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * Data pelanggan (PRD §51–52, §55; Fase 2) — hanya-baca, dihitung dari pesanan lunas yang
 * mencantumkan nomor HP (checkout QR/kiosk, delivery POS). Tidak mengubah checkout atau harga.
 * Poin mengikuti contoh PRD §53 (Rp10.000 = 1 poin) dan otomatis berkurang bila pesanan
 * dibatalkan/direfund (§54) karena hanya pesanan lunas yang dihitung; belum ada penukaran.
 * Nomor HP disamarkan di daftar (PRD §83). Izin: customer.view.
 */
final class CustomerInsights
{
    public const POINT_VALUE = 10_000;

    /** Batas tier dari total belanja (rupiah). */
    public const TIERS = ['Gold' => 1_000_000, 'Silver' => 300_000, 'Member' => 0];

    /** @param array{id: string} $outlet */
    public function list(User $actor, array $outlet, ?string $search): array
    {
        if (! $actor->can('customer.view', AccessScope::outlet($outlet['id']))) {
            throw AccessException::forbidden();
        }
        $paid = DB::table('orders')
            ->where('orders.company_id', $actor->company_id)
            ->where('orders.outlet_id', $outlet['id'])
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereNotNull('orders.customer_phone');
        $rows = (clone $paid)
            ->groupBy('orders.customer_phone')
            ->selectRaw('orders.customer_phone as phone,
                (array_agg(orders.customer_name order by orders.paid_at desc) filter (where orders.customer_name is not null))[1] as name,
                count(*) as transactions, sum(orders.total) as spending, max(orders.paid_at) as last_at, min(orders.paid_at) as first_at')
            ->orderByDesc('spending')
            ->limit(500)
            ->get();
        $favorites = (clone $paid)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('orders.customer_phone', 'order_items.product_name')
            ->selectRaw('orders.customer_phone as phone, order_items.product_name as name, sum(order_items.quantity) as qty')
            ->orderByDesc('qty')->orderBy('order_items.product_name')
            ->get()->groupBy('phone');

        $needle = is_string($search) ? mb_strtolower(trim($search)) : '';
        $digits = preg_replace('/\D/', '', $needle);
        $customers = $rows->map(function ($r) use ($favorites) {
            $spending = (int) $r->spending;
            $transactions = (int) $r->transactions;

            return [
                'key' => substr(hash('sha256', $r->phone), 0, 16),
                'name' => $r->name ?? 'Tanpa nama',
                'phone' => $r->phone,
                'phoneMasked' => self::mask($r->phone),
                'transactions' => $transactions,
                'spending' => $spending,
                'average' => intdiv($spending, max(1, $transactions)),
                'lastAt' => $r->last_at === null ? null : date(DATE_ATOM, strtotime($r->last_at)),
                'firstAt' => $r->first_at === null ? null : date(DATE_ATOM, strtotime($r->first_at)),
                'points' => intdiv($spending, self::POINT_VALUE),
                'tier' => self::tier($spending),
                'favorites' => ($favorites[$r->phone] ?? collect())->take(3)->map(fn ($f) => ['name' => $f->name, 'quantity' => (int) $f->qty])->values()->all(),
            ];
        })->filter(fn ($c) => $needle === '' || str_contains(mb_strtolower($c['name']), $needle) || ($digits !== '' && str_contains($c['phone'], $digits)))
            ->values();

        return [
            'summary' => [
                'customers' => $rows->count(),
                'repeat' => $rows->filter(fn ($r) => (int) $r->transactions >= 2)->count(),
                'spending' => (int) $rows->sum('spending'),
            ],
            // Nomor lengkap hanya untuk detail di halaman; daftar menampilkan versi samaran.
            'customers' => $customers->map(fn ($c) => array_diff_key($c, ['phone' => 1]) + ['phoneFull' => $c['phone']])->all(),
            'pointValue' => self::POINT_VALUE,
            'tiers' => self::TIERS,
        ];
    }

    public static function tier(int $spending): string
    {
        foreach (self::TIERS as $name => $min) {
            if ($spending >= $min) {
                return $name;
            }
        }

        return 'Member';
    }

    /** 081234567890 → 0812****7890. */
    public static function mask(string $phone): string
    {
        $len = strlen($phone);

        return $len <= 8 ? str_repeat('*', max(0, $len - 2)).substr($phone, -2) : substr($phone, 0, 4).str_repeat('*', $len - 8).substr($phone, -4);
    }
}
