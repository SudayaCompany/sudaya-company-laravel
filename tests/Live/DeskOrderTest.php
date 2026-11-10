<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use App\Services\Outlets\OutletSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pesanan meja kantor (permintaan owner 10 November 2026): QR meja → nama, lantai, bagian →
 * antar ke meja (biaya antar per outlet, dihitung server) atau ambil di counter; bayar tunai
 * atau QRIS statis outlet yang dikonfirmasi manual kasir.
 */
class DeskOrderTest extends LiveTestCase
{
    public static array $stored = [];

    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, $this->fakeImages());
        $A = $ctx->createCompany('deska');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Latte Meja', 'categoryId' => $cat, 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'L3-12']);

        return ['A' => $A, ...$users, 'latte' => $latte, 'code' => DB::table('outlets')->where('id', $A['outletId'])->value('code')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MenuImageStorage::class, $this->fakeImages());
    }

    private function fakeImages(): MenuImageStorage
    {
        return new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void
            {
                DeskOrderTest::$stored[$path] = $bytes;
            }

            public function remove(array $paths): void
            {
                foreach ($paths as $path) {
                    unset(DeskOrderTest::$stored[$path]);
                }
            }
        };
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function qrisFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('qris.png', "\x89PNG\r\n\x1a\n".str_repeat('q', 512));
    }

    private function deskOrder(array $overrides = []): array
    {
        return app(OrderEngine::class)->create('qr_table', ['code' => static::$fixture['code']], null, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => 'L3-12', 'customerName' => 'Rina Meja',
            'customerPhone' => '081234500012', 'floor' => '3', 'department' => 'Keuangan', 'paymentMethod' => 'qris',
            'lines' => [['productId' => static::$fixture['latte'], 'modifierIds' => [], 'quantity' => 2, 'notes' => 'less sugar, less ice']],
            ...$overrides,
        ], $this->audit());
    }

    private function expectAccess(string $reason, callable $call, ?string $pattern = null): void
    {
        try {
            $call();
            $this->fail("diharapkan AccessException {$reason}");
        } catch (AccessException $error) {
            $this->assertSame($reason, $error->reason, $error->getMessage());
            if ($pattern !== null) {
                $this->assertMatchesRegularExpression($pattern, $error->getMessage());
            }
        }
    }

    public function test_pengaturan_biaya_antar_dan_qris_outlet(): void
    {
        $settings = app(OutletSettings::class);
        $outletId = static::$fixture['A']['outletId'];
        $this->expectAccess('forbidden', fn () => $settings->updateOrdering($this->actor('cashierA'), $outletId, ['deliveryFee' => '3000'], null, $this->audit()));
        $this->expectAccess('invalid', fn () => $settings->updateOrdering($this->actor('superA'), $outletId, ['deliveryFee' => '2000000'], null, $this->audit()));

        $this->assertSame(['deliveryFee' => 3000, 'qris' => true], $settings->updateOrdering($this->actor('superA'), $outletId, ['deliveryFee' => '3000'], $this->qrisFile(), $this->audit()));
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['delivery_fee', 'qris_image_path']);
        $this->assertSame(3000, (int) $outlet->delivery_fee);
        $this->assertArrayHasKey($outlet->qris_image_path, self::$stored);
        $page = $settings->get($this->actor('superA'), $outletId)['outlet'];
        $this->assertSame(3000, $page['deliveryFee']);
        $this->assertStringContainsString(rawurlencode(basename($outlet->qris_image_path)), (string) $page['qrisImageUrl']);

        // Ganti gambar: file lama dihapus dari Storage.
        $settings->updateOrdering($this->actor('superA'), $outletId, ['deliveryFee' => '3000'], $this->qrisFile(), $this->audit());
        $this->assertArrayNotHasKey($outlet->qris_image_path, self::$stored);
        $this->assertSame(2, $this->ctx()->countAudit(static::$fixture['A']['id'], 'outlet.ordering_updated', $outletId));

        $menu = app(Storefront::class)->load(static::$fixture['code']);
        $this->assertSame([3000, true], [$menu['outlet']['deliveryFee'], $menu['outlet']['qrisAvailable']]);
    }

    public function test_antar_ke_meja_kena_biaya_ambil_sendiri_tidak(): void
    {
        $desk = $this->deskOrder();
        $this->assertSame(53_000, $desk['total'], '2 × 25.000 + biaya antar 3.000');
        $row = DB::table('orders')->where('id', $desk['orderId'])->first();
        $this->assertSame([50_000, 3000, 53_000, 'L3-12', 'Lantai 3 · Keuangan'], [(int) $row->subtotal, (int) $row->delivery_fee, (int) $row->total, $row->table_number, $row->location_note]);
        $this->assertSame('less sugar, less ice', DB::table('order_items')->where('order_id', $desk['orderId'])->value('notes'));
        $payment = DB::table('payments')->where('order_id', $desk['orderId'])->first();
        $this->assertSame(['qris', 'pending', 53_000], [$payment->method, $payment->status, (int) $payment->amount]);

        $guest = app(OrderEngine::class)->guestOrder($desk['orderId'], $desk['accessToken']);
        $this->assertSame([3000, 50_000, 'Lantai 3 · Keuangan', 'qris'], [$guest['deliveryFee'], $guest['subtotal'], $guest['locationNote'], $guest['paymentMethod']]);
        $this->assertNotNull($guest['qrisImageUrl'], 'QRIS outlet tampil selama menunggu bayar');

        $pickup = $this->deskOrder(['type' => 'takeaway', 'paymentMethod' => 'cash']);
        $this->assertSame(50_000, $pickup['total']);
        $this->assertSame(0, (int) DB::table('orders')->where('id', $pickup['orderId'])->value('delivery_fee'));
        $this->assertNull(app(OrderEngine::class)->guestOrder($pickup['orderId'], $pickup['accessToken'])['qrisImageUrl']);
    }

    public function test_qris_dikonfirmasi_manual_kasir_tanpa_masuk_laci(): void
    {
        $sessionId = app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => static::$fixture['A']['outletId'], 'openingAmount' => 100_000], $this->audit())['id'];
        $order = $this->deskOrder();
        $this->expectAccess('forbidden', fn () => app(OrderWorkflow::class)->confirmQris($this->actor('baristaA'), ['orderId' => $order['orderId']], $this->audit()));

        $paid = app(OrderWorkflow::class)->confirmQris($this->actor('cashierA'), ['orderId' => $order['orderId']], $this->audit());
        $this->assertFalse($paid['alreadyPaid']);
        $this->assertNotNull($paid['orderNumber']);
        $row = DB::table('orders')->where('id', $order['orderId'])->first(['status', 'payment_status']);
        $this->assertSame(['queued', 'paid'], [$row->status, $row->payment_status]);
        $payment = DB::table('payments')->where('order_id', $order['orderId'])->first();
        $this->assertSame(['qris', 'paid', null, null], [$payment->method, $payment->status, $payment->cash_session_id, $payment->tendered_amount]);
        $this->assertSame(100_000, CashSessions::totals(DB::table('cash_sessions')->where('id', $sessionId)->first())['expected'], 'QRIS tidak menambah saldo laci');
        $this->assertTrue(app(OrderWorkflow::class)->confirmQris($this->actor('cashierA'), ['orderId' => $order['orderId']], $this->audit())['alreadyPaid']);
        $this->assertNull(app(OrderEngine::class)->guestOrder($order['orderId'], $order['accessToken'])['qrisImageUrl'], 'QRIS hilang setelah lunas');
        $audit = DB::table('audit_logs')->where('company_id', static::$fixture['A']['id'])->where('action', 'payment.confirmed')->where('entity_id', $order['orderId'])->value('after');
        // jsonb menyusun ulang kunci; nilai dibandingkan persis tanpa memedulikan urutan kunci.
        $this->assertEquals(['method' => 'qris', 'amount' => 53_000, 'manualConfirmation' => true], json_decode($audit, true));

        // Tanpa gambar QRIS: pilihan QRIS ditolak dan konfirmasi QRIS tidak tersedia.
        app(OutletSettings::class)->updateOrdering($this->actor('superA'), static::$fixture['A']['outletId'], ['deliveryFee' => '3000', 'removeQris' => true], null, $this->audit());
        $this->expectAccess('invalid', fn () => $this->deskOrder(), '/QRIS belum tersedia/');
        $cashOrder = $this->deskOrder(['paymentMethod' => 'cash']);
        $this->expectAccess('invalid', fn () => app(OrderWorkflow::class)->confirmQris($this->actor('cashierA'), ['orderId' => $cashOrder['orderId']], $this->audit()), '/QRIS belum diatur/');
        $cash = app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $cashOrder['orderId'], 'tenderedAmount' => 60_000], $this->audit());
        $this->assertSame(7_000, $cash['change'], 'tunai: total 53.000 termasuk biaya antar');
    }
}
