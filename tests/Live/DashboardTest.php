<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuExport;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Dashboard\DashboardSummary;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * UX-5: ringkasan dasbor dari data asli, kotak persetujuan Owner, dan ekspor menu ke
 * spreadsheet. Angka dihitung dari pesanan yang dibuat run ini, per outlet dan per tenant.
 */
class DashboardTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('dasha');
        $B = $ctx->createCompany('dashb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'dataAdminA' => $ctx->createUser($A, [['role' => 'admin', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $product = fn (string $name, int $price, ?string $sku = null) => $catalog->createProduct($admin, $A['brandId'], [
            'name' => $name, 'sku' => $sku, 'categoryId' => $coffee, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];

        return [
            'A' => $A, 'B' => $B, ...$users,
            'latte' => $product('Iced Latte', 25000, 'LAT-01'),
            'tea' => $product('Iced Tea', 15000),
            // Teks mirip rumus harus tetap teks di spreadsheet.
            'formula' => $product('=SUM(1+1) Kopi', 18000),
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

        return ['id' => $id, 'timezone' => DB::table('outlets')->where('id', $id)->value('timezone')];
    }

    private function summary(string $actor, string $company = 'A'): array
    {
        return app(DashboardSummary::class)->forOutlet($this->actor($actor), $this->outlet($company));
    }

    private function order(array $lines): string
    {
        return app(OrderEngine::class)->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'customerName' => 'Andi',
            'paymentMethod' => 'cash',
            'lines' => array_map(fn ($l) => ['productId' => static::$fixture[$l[0]], 'modifierIds' => [], 'quantity' => $l[1]], $lines),
        ], $this->audit())['orderId'];
    }

    private function paid(array $lines, int $tendered = 100_000): string
    {
        $id = $this->order($lines);
        app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => $tendered], $this->audit());

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

    /** @return array<string, string> isi part XLSX */
    private function xlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dashx');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $parts;
    }

    public function test_ringkasan_outlet_baru_kosong(): void
    {
        $s = $this->summary('ownerA');

        $this->assertSame(CarbonImmutable::now($this->outlet()['timezone'])->toDateString(), $s['businessDate']);
        $this->assertSame(['revenue' => 0, 'transactions' => 0, 'average' => 0, 'itemsSold' => 0, 'topProducts' => []], $s['sales']);
        $this->assertSame(['pendingPayment' => 0, 'inQueue' => 0, 'completedToday' => 0], $s['orders']);
        $this->assertSame(['open' => null, 'lastClosed' => null], $s['cash']);
        $this->assertSame(0, $s['pendingApprovals']);
        $this->assertSame(0, $s['soldOut']);
    }

    public function test_angka_penjualan_pesanan_kas_dan_persetujuan(): void
    {
        $outletId = static::$fixture['A']['outletId'];
        $cash = app(CashSessions::class);
        $sessionId = $cash->open($this->actor('cashierA'), ['outletId' => $outletId, 'openingAmount' => 100_000], $this->audit())['id'];

        $this->paid([['latte', 2]]);
        $this->paid([['tea', 1]], 20_000);
        $toCancel = $this->paid([['tea', 1]], 15_000);
        $this->order([['latte', 1]]);
        app(OrderWorkflow::class)->cancel($this->actor('baristaA'), ['orderId' => $toCancel, 'reason' => 'salah input QA'], $this->audit());
        DB::table('outlet_products')->updateOrInsert(['outlet_id' => $outletId, 'product_id' => static::$fixture['tea']], ['is_available' => false]);

        $s = $this->summary('ownerA');
        // Pesanan yang baru diajukan batal tetap terhitung sampai disetujui.
        $this->assertSame(80_000, $s['sales']['revenue']);
        $this->assertSame(3, $s['sales']['transactions']);
        $this->assertSame(26_666, $s['sales']['average']);
        $this->assertSame(4, $s['sales']['itemsSold']);
        $this->assertSame([
            ['name' => 'Iced Latte', 'quantity' => 2, 'amount' => 50_000],
            ['name' => 'Iced Tea', 'quantity' => 2, 'amount' => 30_000],
        ], $s['sales']['topProducts']);
        $this->assertSame(['pendingPayment' => 1, 'inQueue' => 3, 'completedToday' => 0], $s['orders']);
        $this->assertSame(180_000, $s['cash']['open']['expected']);
        $this->assertNull($s['cash']['lastClosed']);
        $this->assertSame(1, $s['pendingApprovals']);
        $this->assertSame(1, $s['soldOut']);

        // Kotak persetujuan: Owner boleh memutuskan; pemohon (barista) tidak punya akses lihat.
        $inbox = app(OrderEngine::class)->pendingApprovals($this->actor('ownerA'), $this->outlet());
        $this->assertCount(1, $inbox);
        $this->assertSame($toCancel, $inbox[0]['order']['id']);
        $this->assertSame(15_000, $inbox[0]['order']['total']);
        $this->assertSame('salah input QA', $inbox[0]['reason']);
        $this->assertTrue($inbox[0]['canDecide']);
        $this->expectAccess('forbidden', fn () => app(OrderEngine::class)->pendingApprovals($this->actor('baristaA'), $this->outlet()));
        $this->expectAccess('forbidden', fn () => app(OrderEngine::class)->pendingApprovals($this->actor('cashierA'), $this->outlet()));

        app(OrderWorkflow::class)->decideCancellation($this->actor('ownerA'), ['approvalId' => $inbox[0]['id'], 'approve' => true], $this->audit());
        $s = $this->summary('ownerA');
        $this->assertSame([65_000, 2, 32_500, 3], [$s['sales']['revenue'], $s['sales']['transactions'], $s['sales']['average'], $s['sales']['itemsSold']]);
        $this->assertSame(0, $s['pendingApprovals']);
        $this->assertSame(2, $s['orders']['inQueue']);
        $this->assertSame([], app(OrderEngine::class)->pendingApprovals($this->actor('ownerA'), $this->outlet()));

        // Tutup kas dengan selisih: kartu kas menampilkan sesi terakhir dan selisihnya.
        $expected = CashSessions::totals(DB::table('cash_sessions')->where('id', $sessionId)->first())['expected'];
        $this->assertSame(180_000, $expected);
        $cash->close($this->actor('managerA'), ['sessionId' => $sessionId, 'actualAmount' => $expected - 5_000, 'reason' => 'selisih QA'], $this->audit());
        $s = $this->summary('ownerA');
        $this->assertNull($s['cash']['open']);
        $this->assertSame(['expected' => 180_000, 'actual' => 175_000, 'variance' => -5_000], array_intersect_key($s['cash']['lastClosed'], array_flip(['expected', 'actual', 'variance'])));
    }

    public function test_izin_menentukan_bagian_ringkasan(): void
    {
        // Admin data: dasbor + menu saja, tanpa angka penjualan/pesanan/kas/persetujuan.
        $s = $this->summary('dataAdminA');
        $this->assertSame([null, null, null, null], [$s['sales'], $s['orders'], $s['cash'], $s['pendingApprovals']]);
        $this->assertIsInt($s['soldOut']);

        // Kasir: pesanan dan kas, tanpa laporan penjualan dan persetujuan.
        $s = $this->summary('cashierA');
        $this->assertNull($s['sales']);
        $this->assertNull($s['pendingApprovals']);
        $this->assertIsArray($s['orders']);
        $this->assertIsArray($s['cash']);

        $s = $this->summary('managerA');
        $this->assertIsArray($s['sales']);
        $this->assertIsInt($s['pendingApprovals']);
    }

    public function test_tenant_lain_tidak_ikut_terhitung(): void
    {
        // Company B tidak punya transaksi; outlet A yang ditembak langsung tetap nol.
        foreach (['B', 'A'] as $company) {
            $s = $this->summary('superB', $company);
            $this->assertSame(0, $s['sales']['revenue'], "outlet {$company}");
            $this->assertSame([], $s['sales']['topProducts']);
            $this->assertSame(['pendingPayment' => 0, 'inQueue' => 0, 'completedToday' => 0], $s['orders']);
            $this->assertSame(['open' => null, 'lastClosed' => null], $s['cash']);
            $this->assertSame(0, $s['pendingApprovals']);
            $this->assertSame(0, $s['soldOut']);
            $this->assertSame([], app(OrderEngine::class)->pendingApprovals($this->actor('superB'), $this->outlet($company)));
        }
        $outlets = array_column(app(OrderEngine::class)->outletsWithPermission($this->actor('superB'), 'dashboard.view'), 'id');
        $this->assertNotContains(static::$fixture['A']['outletId'], $outlets);
    }

    public function test_ekspor_menu_xlsx(): void
    {
        $file = app(MenuExport::class)->xlsx($this->actor('ownerA'));
        $this->assertMatchesRegularExpression('/^menu-[a-z0-9-]+-\d{4}-\d{2}-\d{2}\.xlsx$/', $file['filename']);
        $this->assertSame(3, $file['rows']);
        $parts = $this->xlsx($file['bytes']);
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertNotFalse(simplexml_load_string($sheet));
        foreach (['Kategori', 'Nama menu', 'Harga dasar (Rp)', 'Iced Latte', 'LAT-01', 'Iced Tea', 'Habis', 'Tersedia', 'Aktif'] as $text) {
            $this->assertStringContainsString($text, $sheet);
        }
        $this->assertStringContainsString('<v>25000</v>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet, 'tidak ada rumus');
        $this->assertStringContainsString('=SUM(1+1) Kopi', $sheet);

        // Tanpa izin menu: ditolak. Tenant lain: hanya menu brand-nya sendiri.
        $this->expectAccess('forbidden', fn () => app(MenuExport::class)->xlsx($this->actor('cashierA')));
        $other = $this->xlsx(app(MenuExport::class)->xlsx($this->actor('superB'))['bytes'])['xl/worksheets/sheet1.xml'];
        $this->assertStringNotContainsString('Iced Latte', $other);
        $this->expectAccess('not_found', fn () => app(MenuExport::class)->xlsx($this->actor('superB'), static::$fixture['A']['brandId']));
    }
}
