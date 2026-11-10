<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Laporan penjualan (PRD §61–66): angka dihitung dari pesanan lunas yang dibuat run ini,
 * dibandingkan periode sebelumnya, dipecah harian/per jam/kategori/menu/metode, diekspor
 * ke spreadsheet; hanya Owner/Manager (report.sales) dan tidak pernah lintas tenant.
 */
class SalesReportTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('repa');
        $B = $ctx->createCompany('repb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $tea = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Non-coffee'], $audit)['id'];
        $product = fn (string $name, int $price, string $category) => $catalog->createProduct($admin, $A['brandId'], [
            'name' => $name, 'sku' => null, 'categoryId' => $category, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];

        return [
            'A' => $A, 'B' => $B, ...$users,
            'latte' => $product('Iced Latte', 25000, $coffee),
            'tea' => $product('Iced Tea', 15000, $tea),
        ];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function outlet(string $company = 'A'): array
    {
        $id = static::$fixture[$company]['outletId'];
        $row = DB::table('outlets')->where('id', $id)->first(['name', 'timezone']);

        return ['id' => $id, 'name' => $row->name, 'timezone' => $row->timezone];
    }

    private function today(): string
    {
        return CarbonImmutable::now($this->outlet()['timezone'])->toDateString();
    }

    private function report(string $actor, ?string $from = null, ?string $to = null, string $company = 'A'): array
    {
        return app(SalesReport::class)->report($this->actor($actor), $this->outlet($company), $from ?? $this->today(), $to ?? $this->today());
    }

    private function order(array $lines): string
    {
        return app(OrderEngine::class)->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'customerName' => 'Laporan QA',
            'paymentMethod' => 'cash',
            'lines' => array_map(fn ($l) => ['productId' => static::$fixture[$l[0]], 'modifierIds' => [], 'quantity' => $l[1]], $lines),
        ], $this->audit())['orderId'];
    }

    private function paid(array $lines): string
    {
        $id = $this->order($lines);
        app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => 200_000], $this->audit());

        return $id;
    }

    private function expectAccess(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("diharapkan AccessException {$reason}");
        } catch (AccessException $error) {
            $this->assertSame($reason, $error->reason, $error->getMessage());
        }
    }

    public function test_laporan_kosong_untuk_outlet_baru(): void
    {
        $r = $this->report('ownerA');

        $this->assertSame([$this->today(), $this->today(), 1], [$r['from'], $r['to'], $r['days']]);
        $this->assertSame(['revenue' => 0, 'transactions' => 0, 'average' => 0, 'itemsSold' => 0, 'voided' => ['count' => 0, 'amount' => 0]], $r['summary']);
        $this->assertSame([['date' => $this->today(), 'transactions' => 0, 'revenue' => 0]], $r['daily']);
        $this->assertCount(24, $r['hourly']);
        $this->assertSame(0, array_sum(array_column($r['hourly'], 'transactions')));
        $this->assertSame([[], [], []], [$r['products'], $r['categories'], $r['methods']]);
        $yesterday = CarbonImmutable::parse($this->today())->subDay()->toDateString();
        $this->assertSame(['from' => $yesterday, 'to' => $yesterday, 'revenue' => 0, 'transactions' => 0, 'itemsSold' => 0], $r['previous']);
    }

    public function test_angka_laporan_dari_pesanan_lunas(): void
    {
        app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => static::$fixture['A']['outletId'], 'openingAmount' => 100_000], $this->audit());
        $this->paid([['latte', 2]]);
        $this->paid([['tea', 1]]);
        $this->paid([['latte', 1], ['tea', 1]]);
        $cancelled = $this->paid([['tea', 1]]);
        $this->order([['latte', 3]]); // belum dibayar: tidak dihitung

        // Pembatalan pesanan lunas yang disetujui Owner keluar dari omzet, tercatat sebagai batal.
        app(OrderWorkflow::class)->cancel($this->actor('baristaA'), ['orderId' => $cancelled, 'reason' => 'salah input laporan QA'], $this->audit());
        $approval = app(OrderEngine::class)->pendingApprovals($this->actor('ownerA'), $this->outlet())[0]['id'];
        app(OrderWorkflow::class)->decideCancellation($this->actor('ownerA'), ['approvalId' => $approval, 'approve' => true], $this->audit());

        $r = $this->report('managerA');
        $this->assertSame(['revenue' => 105_000, 'transactions' => 3, 'average' => 35_000, 'itemsSold' => 5, 'voided' => ['count' => 1, 'amount' => 15_000]], $r['summary']);
        $this->assertSame([['date' => $this->today(), 'transactions' => 3, 'revenue' => 105_000]], $r['daily']);
        $this->assertSame([
            ['name' => 'Iced Latte', 'quantity' => 3, 'revenue' => 75_000],
            ['name' => 'Iced Tea', 'quantity' => 2, 'revenue' => 30_000],
        ], $r['products']);
        $this->assertSame([
            ['name' => 'Coffee', 'quantity' => 3, 'revenue' => 75_000, 'share' => 71.4],
            ['name' => 'Non-coffee', 'quantity' => 2, 'revenue' => 30_000, 'share' => 28.6],
        ], $r['categories']);
        $this->assertSame([['method' => 'cash', 'label' => 'Tunai', 'transactions' => 3, 'amount' => 105_000]], $r['methods']);

        // Per jam memakai jam bayar di zona waktu outlet.
        $tz = $this->outlet()['timezone'];
        $expected = array_fill(0, 24, 0);
        foreach (DB::table('orders')->where('outlet_id', static::$fixture['A']['outletId'])->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->pluck('paid_at') as $paidAt) {
            $expected[CarbonImmutable::parse($paidAt)->setTimezone($tz)->hour]++;
        }
        $this->assertSame($expected, array_column($r['hourly'], 'transactions'));
        $this->assertSame(105_000, array_sum(array_column($r['hourly'], 'revenue')));
    }

    public function test_periode_sebelumnya_dan_rentang_harian(): void
    {
        // Pindahkan satu pesanan lunas run ini ke kemarin (data test saja).
        $today = $this->today();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $tea = DB::table('orders')->where('outlet_id', static::$fixture['A']['outletId'])->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->where('total', 15_000)->value('id');
        DB::table('orders')->where('id', $tea)->update(['business_date' => $yesterday]);

        $r = $this->report('ownerA');
        $this->assertSame([90_000, 2], [$r['summary']['revenue'], $r['summary']['transactions']]);
        $this->assertSame(['from' => $yesterday, 'to' => $yesterday, 'revenue' => 15_000, 'transactions' => 1, 'itemsSold' => 1], $r['previous']);

        // Dua hari (urutan tanggal terbalik tetap diterima): harian lengkap, pembanding dua hari sebelumnya.
        $r = $this->report('ownerA', $today, $yesterday);
        $this->assertSame([$yesterday, $today, 2], [$r['from'], $r['to'], $r['days']]);
        $this->assertSame([
            ['date' => $yesterday, 'transactions' => 1, 'revenue' => 15_000],
            ['date' => $today, 'transactions' => 2, 'revenue' => 90_000],
        ], $r['daily']);
        $this->assertSame(105_000, $r['summary']['revenue']);
        $this->assertSame([CarbonImmutable::parse($today)->subDays(3)->toDateString(), CarbonImmutable::parse($today)->subDays(2)->toDateString(), 0], [$r['previous']['from'], $r['previous']['to'], $r['previous']['revenue']]);

        $this->expectAccess('invalid', fn () => $this->report('ownerA', CarbonImmutable::parse($today)->subDays(SalesReport::MAX_DAYS)->toDateString(), $today));
    }

    public function test_ekspor_spreadsheet_beberapa_sheet(): void
    {
        $file = app(SalesReport::class)->export($this->actor('ownerA'), $this->outlet(), CarbonImmutable::parse($this->today())->subDay()->toDateString(), $this->today());
        $this->assertMatchesRegularExpression('/^penjualan-[a-z0-9-]+-\d{4}-\d{2}-\d{2}-sd-\d{4}-\d{2}-\d{2}\.xlsx$/', $file['filename']);

        $path = tempnam(sys_get_temp_dir(), 'repx');
        file_put_contents($path, $file['bytes']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $summary = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $menu = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
        $count = $zip->numFiles;
        $zip->close();
        unlink($path);

        $this->assertSame(11, $count, '5 part dasar + 6 sheet');
        foreach (['Ringkasan', 'Harian', 'Menu terlaris', 'Kategori', 'Per jam', 'Metode bayar'] as $i => $name) {
            $this->assertStringContainsString('<sheet name="'.$name.'" sheetId="'.($i + 1).'"', $workbook);
        }
        $this->assertStringContainsString('<v>105000</v>', $summary, 'omzet dua hari sebagai angka');
        $this->assertStringContainsString('Iced Latte', $menu);
    }

    public function test_izin_dan_isolasi_tenant(): void
    {
        foreach (['cashierA', 'baristaA'] as $actor) {
            $this->expectAccess('forbidden', fn () => $this->report($actor));
        }
        // Super admin tenant lain tidak bisa membaca outlet A, dan laporannya sendiri kosong.
        $this->expectAccess('not_found', fn () => app(SalesReport::class)->report($this->actor('superB'), $this->outlet('A'), $this->today(), $this->today()));
        $this->assertSame(0, $this->report('superB', null, null, 'B')['summary']['revenue']);
    }
}
