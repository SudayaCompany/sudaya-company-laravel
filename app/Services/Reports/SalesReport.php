<?php

namespace App\Services\Reports;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Finance\Expenses;
use App\Support\Access\AccessScope;
use App\Support\Spreadsheet\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan penjualan (PRD §61–66, §111–113): omzet, transaksi, rata-rata, item terjual,
 * perbandingan periode sebelumnya, harian, per jam, per kategori, menu terlaris, metode
 * bayar, dan pembatalan. Angka = pesanan lunas per tanggal operasional (business_date),
 * tidak termasuk yang dibatalkan/direfund — sama dengan dasbor. Hanya-baca, izin report.sales.
 */
final class SalesReport
{
    public const MAX_DAYS = 92;

    public const METHODS = ['cash' => 'Tunai', 'qris' => 'QRIS'];

    /** @param array{id: string, timezone: string} $outlet */
    public function report(User $actor, array $outlet, string $from, string $to): array
    {
        if (! $actor->can('report.sales', AccessScope::outlet($outlet['id']))) {
            throw AccessException::forbidden();
        }
        // Outlet tenant lain tidak pernah dilaporkan, walau grant user berlaku se-company.
        if (! DB::table('outlets')->where('id', $outlet['id'])->where('company_id', $actor->company_id)->exists()) {
            throw AccessException::notFound();
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $days = (int) $start->diffInDays($end) + 1;
        if ($days > self::MAX_DAYS) {
            throw new AccessException('invalid', 'Rentang laporan maksimal '.self::MAX_DAYS.' hari.');
        }
        $prevTo = $start->subDay()->toDateString();
        $prevFrom = $start->subDays($days)->toDateString();
        $where = ['company_id' => $actor->company_id, 'outlet_id' => $outlet['id']];
        $paid = fn (string $a, string $b) => DB::table('orders')
            ->where('orders.company_id', $where['company_id'])
            ->where('orders.outlet_id', $where['outlet_id'])
            ->whereBetween('orders.business_date', [$a, $b])
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.status', ['cancelled', 'refunded']);

        $totals = $paid($from, $to)->selectRaw('count(*) as n, coalesce(sum(total), 0) as revenue')->first();
        $previous = $paid($prevFrom, $prevTo)->selectRaw('count(*) as n, coalesce(sum(total), 0) as revenue')->first();
        $itemsSold = fn (string $a, string $b) => (int) $paid($a, $b)->join('order_items', 'order_items.order_id', '=', 'orders.id')->sum('order_items.quantity');
        $items = $itemsSold($from, $to);

        $daily = $paid($from, $to)->groupBy('orders.business_date')
            ->selectRaw('orders.business_date::text as d, count(*) as n, sum(orders.total) as revenue')->get()->keyBy('d');
        $hourly = $paid($from, $to)
            ->selectRaw('extract(hour from orders.paid_at at time zone ?)::int as h, count(*) as n, sum(orders.total) as revenue', [$outlet['timezone']])
            ->groupByRaw('1')->get()->keyBy('h');
        $products = $paid($from, $to)->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('order_items.product_name')
            ->selectRaw('order_items.product_name as name, sum(order_items.quantity) as qty, sum(order_items.line_total) as revenue')
            ->orderByDesc('qty')->orderByDesc('revenue')->orderBy('order_items.product_name')->limit(10)->get();
        $categories = $paid($from, $to)->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.name')
            ->selectRaw("coalesce(categories.name, 'Tanpa kategori') as name, sum(order_items.quantity) as qty, sum(order_items.line_total) as revenue")
            ->orderByDesc('revenue')->get();
        $methods = DB::table('payments')->joinSub($paid($from, $to)->select('orders.id'), 'p', 'p.id', '=', 'payments.order_id')
            ->where('payments.status', 'paid')->groupBy('payments.method')
            ->selectRaw('payments.method, count(*) as n, sum(payments.amount) as amount')->orderByDesc('amount')->get();
        $voided = DB::table('orders')->where($where)->whereBetween('business_date', [$from, $to])
            ->whereIn('status', ['cancelled', 'refunded'])->whereIn('payment_status', ['paid', 'refunded'])
            ->selectRaw('count(*) as n, coalesce(sum(total), 0) as amount')->first();

        $revenue = (int) $totals->revenue;
        $transactions = (int) $totals->n;
        $categoryTotal = max(1, (int) $categories->sum('revenue'));

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'previous' => ['from' => $prevFrom, 'to' => $prevTo, 'revenue' => (int) $previous->revenue, 'transactions' => (int) $previous->n, 'itemsSold' => $itemsSold($prevFrom, $prevTo)],
            'summary' => [
                'revenue' => $revenue,
                'transactions' => $transactions,
                'average' => $transactions === 0 ? 0 : intdiv($revenue, $transactions),
                'itemsSold' => $items,
                'voided' => ['count' => (int) $voided->n, 'amount' => (int) $voided->amount],
                // Pengeluaran outlet yang disetujui pada periode yang sama (Fase 2, PRD §59).
                'expenses' => Expenses::approvedTotal($actor->company_id, $outlet['id'], $from, $to),
            ],
            'daily' => array_map(function (int $i) use ($start, $daily) {
                $date = $start->addDays($i)->toDateString();
                $row = $daily[$date] ?? null;

                return ['date' => $date, 'transactions' => (int) ($row->n ?? 0), 'revenue' => (int) ($row->revenue ?? 0)];
            }, range(0, $days - 1)),
            'hourly' => array_map(fn (int $h) => ['hour' => $h, 'transactions' => (int) ($hourly[$h]->n ?? 0), 'revenue' => (int) ($hourly[$h]->revenue ?? 0)], range(0, 23)),
            'products' => $products->map(fn ($p) => ['name' => $p->name, 'quantity' => (int) $p->qty, 'revenue' => (int) $p->revenue])->all(),
            'categories' => $categories->map(fn ($c) => ['name' => $c->name, 'quantity' => (int) $c->qty, 'revenue' => (int) $c->revenue, 'share' => round((int) $c->revenue * 100 / $categoryTotal, 1)])->all(),
            'methods' => $methods->map(fn ($m) => ['method' => $m->method, 'label' => self::METHODS[$m->method] ?? $m->method, 'transactions' => (int) $m->n, 'amount' => (int) $m->amount])->all(),
        ];
    }

    /** @return array{filename: string, bytes: string} */
    public function export(User $actor, array $outlet, string $from, string $to): array
    {
        $r = $this->report($actor, $outlet, $from, $to);
        $s = $r['summary'];
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($outlet['name'] ?? 'outlet')), '-') ?: 'outlet';

        return [
            'filename' => "penjualan-{$slug}-{$r['from']}-sd-{$r['to']}.xlsx",
            'bytes' => XlsxWriter::workbook([
                ['name' => 'Ringkasan', 'header' => ['Metrik', 'Nilai'], 'widths' => [36, 20], 'rows' => [
                    ['Periode', "{$r['from']} s/d {$r['to']}"],
                    ['Omzet bersih (Rp)', $s['revenue']],
                    ['Transaksi', $s['transactions']],
                    ['Rata-rata per transaksi (Rp)', $s['average']],
                    ['Item terjual', $s['itemsSold']],
                    ['Pesanan lunas yang dibatalkan/direfund', $s['voided']['count']],
                    ['Nilai pembatalan/refund (Rp)', $s['voided']['amount']],
                    ['Pengeluaran disetujui (Rp)', $s['expenses']],
                    ['Omzet dikurangi pengeluaran (Rp)', $s['revenue'] - $s['expenses']],
                    ["Omzet periode sebelumnya {$r['previous']['from']} s/d {$r['previous']['to']} (Rp)", $r['previous']['revenue']],
                ]],
                ['name' => 'Harian', 'header' => ['Tanggal', 'Transaksi', 'Omzet (Rp)'], 'widths' => [14, 12, 16],
                    'rows' => array_map(fn ($d) => [$d['date'], $d['transactions'], $d['revenue']], $r['daily'])],
                ['name' => 'Menu terlaris', 'header' => ['Menu', 'Terjual', 'Omzet (Rp)'], 'widths' => [36, 10, 16],
                    'rows' => array_map(fn ($p) => [$p['name'], $p['quantity'], $p['revenue']], $r['products'])],
                ['name' => 'Kategori', 'header' => ['Kategori', 'Terjual', 'Omzet (Rp)', 'Porsi (%)'], 'widths' => [28, 10, 16, 10],
                    'rows' => array_map(fn ($c) => [$c['name'], $c['quantity'], $c['revenue'], (string) $c['share']], $r['categories'])],
                ['name' => 'Per jam', 'header' => ['Jam', 'Transaksi', 'Omzet (Rp)'], 'widths' => [10, 12, 16],
                    'rows' => array_map(fn ($h) => [sprintf('%02d.00', $h['hour']), $h['transactions'], $h['revenue']], $r['hourly'])],
                ['name' => 'Metode bayar', 'header' => ['Metode', 'Transaksi', 'Jumlah (Rp)'], 'widths' => [16, 12, 16],
                    'rows' => array_map(fn ($m) => [$m['label'], $m['transactions'], $m['amount']], $r['methods'])],
            ]),
        ];
    }
}
