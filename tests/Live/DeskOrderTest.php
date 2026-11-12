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
 * Pesanan meja kantor. 10 November 2026: QR meja → nama, lantai, bagian → antar ke meja atau ambil
 * di counter; QRIS statis outlet dikonfirmasi manual. 11 November 2026 (keputusan owner): biaya
 * antar dihapus; antar ke meja + tunai = bayar ke barista saat diantar (langsung masuk antrean,
 * stok dipotong saat masuk antrean, barista menandai lunas, tidak bisa ditutup sebelum lunas).
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
        $roti = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Roti Meja', 'categoryId' => $cat, 'basePrice' => '20000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        // Produk jadi tertaut stok: dipotong saat pesanan bayar-saat-diantar masuk antrean.
        $item = DB::table('inventory_items')->insertGetId([
            'company_id' => $A['id'], 'brand_id' => $A['brandId'], 'outlet_id' => $A['outletId'],
            'sku' => 'STK-DESK-'.$ctx->runId, 'name' => 'Roti Meja', 'kind' => 'finished_good', 'unit' => 'pcs', 'stock_qty' => 10,
        ]);
        DB::table('outlet_products')->updateOrInsert(['outlet_id' => $A['outletId'], 'product_id' => $roti], ['inventory_item_id' => $item, 'is_available' => true]);
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'L3-12']);

        return ['A' => $A, ...$users, 'latte' => $latte, 'roti' => $roti, 'item' => $item, 'code' => DB::table('outlets')->where('id', $A['outletId'])->value('code')];
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

    private function stock(): int
    {
        return (int) DB::table('inventory_items')->where('id', static::$fixture['item'])->value('stock_qty');
    }

    private function deskOrder(array $overrides = []): array
    {
        return app(OrderEngine::class)->create('qr_table', ['code' => static::$fixture['code']], null, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => 'L3-12', 'customerName' => 'Rina Meja',
            'customerPhone' => '081234500012', 'floor' => '3', 'area' => 'Keuangan', 'department' => 'Fundraising', 'paymentMethod' => 'qris',
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

    private function move(string $orderId, string $from, string $to): array
    {
        return app(OrderWorkflow::class)->updateStatus($this->actor('cashierA'), ['orderId' => $orderId, 'from' => $from, 'to' => $to], $this->audit());
    }

    public function test_qris_outlet_tanpa_biaya_antar(): void
    {
        $settings = app(OutletSettings::class);
        $outletId = static::$fixture['A']['outletId'];
        $this->expectAccess('forbidden', fn () => $settings->updateOrdering($this->actor('cashierA'), $outletId, [], $this->qrisFile(), $this->audit()));

        $this->assertSame(['qris' => true], $settings->updateOrdering($this->actor('superA'), $outletId, [], $this->qrisFile(), $this->audit()));
        $path = DB::table('outlets')->where('id', $outletId)->value('qris_image_path');
        $this->assertArrayHasKey($path, self::$stored);
        $page = $settings->get($this->actor('superA'), $outletId)['outlet'];
        $this->assertArrayNotHasKey('deliveryFee', $page, 'biaya antar dihapus dari pengaturan outlet');
        $this->assertStringContainsString(rawurlencode(basename($path)), (string) $page['qrisImageUrl']);

        // Ganti gambar: file lama dihapus dari Storage; biaya antar tidak lagi tersimpan.
        $settings->updateOrdering($this->actor('superA'), $outletId, ['deliveryFee' => '3000'], $this->qrisFile(), $this->audit());
        $this->assertArrayNotHasKey($path, self::$stored);
        $this->assertSame(0, (int) DB::table('outlets')->where('id', $outletId)->value('delivery_fee'));
        $this->assertSame(2, $this->ctx()->countAudit(static::$fixture['A']['id'], 'outlet.ordering_updated', $outletId));

        $menu = app(Storefront::class)->load(static::$fixture['code']);
        $this->assertTrue($menu['outlet']['qrisAvailable']);
        $this->assertArrayNotHasKey('deliveryFee', $menu['outlet']);
    }

    public function test_antar_ke_meja_tunai_langsung_ke_barista_bayar_saat_diantar(): void
    {
        $sessionId = app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => static::$fixture['A']['outletId'], 'openingAmount' => 100_000], $this->audit())['id'];
        $before = $this->stock();
        $order = $this->deskOrder(['paymentMethod' => 'cash', 'lines' => [['productId' => static::$fixture['roti'], 'modifierIds' => [], 'quantity' => 2, 'notes' => 'hangatkan']]]);
        $this->assertSame(40_000, $order['total'], '2 × 20.000, tanpa biaya antar');

        $row = DB::table('orders')->where('id', $order['orderId'])->first();
        $this->assertSame(['queued', 'pending', true, null, 0], [$row->status, $row->payment_status, (bool) $row->pay_on_delivery, $row->expires_at, (int) $row->delivery_fee]);
        $this->assertNotNull($row->queue_number);
        $this->assertNotNull($row->order_number);
        $this->assertSame($before - 2, $this->stock(), 'stok dipotong saat masuk antrean');
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'order.queued', $order['orderId']));

        // Tidak pernah kedaluwarsa otomatis (fungsi pg_cron yang sama).
        $this->assertSame(0, (int) DB::selectOne('select public.expire_pending_orders(200, ?::uuid) as n', [$order['orderId']])->n);

        $board = app(OrderEngine::class)->kdsBoard($this->actor('cashierA'), ['id' => static::$fixture['A']['outletId']]);
        $card = collect($board)->firstWhere('id', $order['orderId']);
        $this->assertNotNull($card, 'masuk layar barista sebelum dibayar');
        $this->assertSame(['pending', true, 40_000], [$card['paymentStatus'], $card['payOnDelivery'], $card['total']]);
        $this->assertNotNull($card['queuedAt']);

        $guest = app(OrderEngine::class)->guestOrder($order['orderId'], $order['accessToken']);
        $this->assertSame([true, 'queued', $row->order_number, 'cash'], [$guest['payOnDelivery'], $guest['status'], $guest['orderNumber'], $guest['paymentMethod']]);
        $this->assertArrayNotHasKey('deliveryFee', $guest);

        $this->move($order['orderId'], 'queued', 'preparing');
        $this->move($order['orderId'], 'preparing', 'ready');
        $this->expectAccess('conflict', fn () => $this->move($order['orderId'], 'ready', 'completed'), '/Terima pembayaran dulu/');

        // Barista menerima uang di meja lalu menandai lunas; masuk laci kas outlet yang terbuka.
        // Keputusan owner 12 November 2026: barista hanya absen; kasir yang menerima uang dan memproses pesanan.
        $this->expectAccess('forbidden', fn () => app(OrderWorkflow::class)->confirmCash($this->actor('baristaA'), ['orderId' => $order['orderId'], 'tenderedAmount' => 50_000], $this->audit()));
        $this->expectAccess('forbidden', fn () => app(OrderEngine::class)->kdsBoard($this->actor('baristaA'), ['id' => static::$fixture['A']['outletId']]));
        $paid = app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $order['orderId'], 'tenderedAmount' => 50_000], $this->audit());
        $this->assertSame([false, 10_000, $row->order_number], [$paid['alreadyPaid'], $paid['change'], $paid['orderNumber']]);
        $after = DB::table('orders')->where('id', $order['orderId'])->first();
        $this->assertSame(['ready', 'paid', $row->queue_number], [$after->status, $after->payment_status, $after->queue_number], 'status produksi tidak berubah saat dibayar');
        $this->assertNotNull($after->paid_at);
        $payment = DB::table('payments')->where('order_id', $order['orderId'])->first();
        $this->assertSame(['cash', 'paid', $sessionId, 50_000, 10_000, static::$fixture['cashierA']['id']], [$payment->method, $payment->status, $payment->cash_session_id, (int) $payment->tendered_amount, (int) $payment->change_amount, $payment->confirmed_by]);
        $this->assertSame(140_000, CashSessions::totals(DB::table('cash_sessions')->where('id', $sessionId)->first())['expected'], 'uang barista masuk laci outlet');
        $this->assertSame($before - 2, $this->stock(), 'stok tidak dipotong dua kali');
        $audit = DB::table('audit_logs')->where('company_id', static::$fixture['A']['id'])->where('action', 'payment.confirmed')->where('entity_id', $order['orderId'])->value('after');
        // jsonb menyusun ulang kunci; nilai dibandingkan persis tanpa memedulikan urutan kunci.
        $this->assertEquals(['method' => 'cash', 'amount' => 40_000, 'tendered' => 50_000, 'change' => 10_000, 'payOnDelivery' => true], json_decode($audit, true));
        $this->assertTrue(app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $order['orderId'], 'tenderedAmount' => 50_000], $this->audit())['alreadyPaid']);

        $this->move($order['orderId'], 'ready', 'completed');
        $this->assertSame('completed', DB::table('orders')->where('id', $order['orderId'])->value('status'));
    }

    public function test_batal_sebelum_dibayar_mengembalikan_stok_dan_stok_kurang_ditolak(): void
    {
        $before = $this->stock();
        $order = $this->deskOrder(['paymentMethod' => 'cash', 'lines' => [['productId' => static::$fixture['roti'], 'modifierIds' => [], 'quantity' => 1, 'notes' => null]]]);
        $this->assertSame($before - 1, $this->stock());

        $result = app(OrderWorkflow::class)->cancel($this->actor('cashierA'), ['orderId' => $order['orderId'], 'reason' => 'Pelanggan tidak ada di meja'], $this->audit());
        $this->assertSame('cancelled', $result['result']);
        $row = DB::table('orders')->where('id', $order['orderId'])->first(['status', 'payment_status']);
        $this->assertSame(['cancelled', 'expired'], [$row->status, $row->payment_status]);
        $this->assertSame($before, $this->stock(), 'stok kembali');
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'inventory.sale_reversed', $order['orderId']));

        // Stok tidak cukup: checkout ditolak dan tidak ada pesanan yang dibuat.
        $key = (string) Str::uuid();
        try {
            $this->deskOrder(['paymentMethod' => 'cash', 'idempotencyKey' => $key, 'lines' => [['productId' => static::$fixture['roti'], 'modifierIds' => [], 'quantity' => 50, 'notes' => null]]]);
            $this->fail('stok kurang harus ditolak');
        } catch (AccessException) {
            $this->assertFalse(DB::table('orders')->where('idempotency_key', $key)->exists());
        }
        $this->assertSame($before, $this->stock());
    }

    public function test_qris_dan_ambil_di_counter_tetap_bayar_dulu(): void
    {
        $sessionId = DB::table('cash_sessions')->where('outlet_id', static::$fixture['A']['outletId'])->where('status', 'open')->value('id')
            ?? app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => static::$fixture['A']['outletId'], 'openingAmount' => 100_000], $this->audit())['id'];
        $expected = CashSessions::totals(DB::table('cash_sessions')->where('id', $sessionId)->first())['expected'];

        $order = $this->deskOrder();
        $this->assertSame(50_000, $order['total']);
        $row = DB::table('orders')->where('id', $order['orderId'])->first();
        $this->assertSame('Fundraising', $row->customer_department);
        $this->assertSame(['pending_payment', 'pending', false, 'L3-12', 'Lantai 3 · Keuangan'], [$row->status, $row->payment_status, (bool) $row->pay_on_delivery, $row->table_number, $row->location_note]);
        $this->assertNotNull($row->expires_at);
        $this->assertSame('less sugar, less ice', DB::table('order_items')->where('order_id', $order['orderId'])->value('notes'));
        $board = app(OrderEngine::class)->kdsBoard($this->actor('cashierA'), ['id' => static::$fixture['A']['outletId']]);
        $this->assertNull(collect($board)->firstWhere('id', $order['orderId']), 'QRIS belum dikonfirmasi tidak masuk layar barista');
        $this->assertNotNull(app(OrderEngine::class)->guestOrder($order['orderId'], $order['accessToken'])['qrisImageUrl']);

        // Barista kini boleh mengonfirmasi QRIS (cek dana di aplikasi QRIS outlet).
        $paid = app(OrderWorkflow::class)->confirmQris($this->actor('cashierA'), ['orderId' => $order['orderId']], $this->audit());
        $this->assertFalse($paid['alreadyPaid']);
        $this->assertSame(['queued', 'paid'], [DB::table('orders')->where('id', $order['orderId'])->value('status'), DB::table('orders')->where('id', $order['orderId'])->value('payment_status')]);
        $payment = DB::table('payments')->where('order_id', $order['orderId'])->first();
        $this->assertSame(['qris', 'paid', null, null], [$payment->method, $payment->status, $payment->cash_session_id, $payment->tendered_amount]);
        $this->assertSame($expected, CashSessions::totals(DB::table('cash_sessions')->where('id', $sessionId)->first())['expected'], 'QRIS tidak menambah saldo laci');
        $audit = DB::table('audit_logs')->where('company_id', static::$fixture['A']['id'])->where('action', 'payment.confirmed')->where('entity_id', $order['orderId'])->value('after');
        $this->assertEquals(['method' => 'qris', 'amount' => 50_000, 'manualConfirmation' => true], json_decode($audit, true));
        $this->assertNull(app(OrderEngine::class)->guestOrder($order['orderId'], $order['accessToken'])['qrisImageUrl'], 'QRIS hilang setelah lunas');

        // Ambil di counter + tunai: tetap bayar di kasir dulu (tidak masuk antrean sebelum lunas).
        $pickup = $this->deskOrder(['type' => 'takeaway', 'paymentMethod' => 'cash']);
        $pickupRow = DB::table('orders')->where('id', $pickup['orderId'])->first(['status', 'pay_on_delivery', 'expires_at']);
        $this->assertSame(['pending_payment', false], [$pickupRow->status, (bool) $pickupRow->pay_on_delivery]);
        $this->assertNotNull($pickupRow->expires_at);

        // Tanpa gambar QRIS: pilihan QRIS ditolak dan konfirmasi QRIS tidak tersedia.
        app(OutletSettings::class)->updateOrdering($this->actor('superA'), static::$fixture['A']['outletId'], ['removeQris' => true], null, $this->audit());
        $this->expectAccess('invalid', fn () => $this->deskOrder(), '/QRIS belum tersedia/');
        $this->expectAccess('invalid', fn () => app(OrderWorkflow::class)->confirmQris($this->actor('cashierA'), ['orderId' => $pickup['orderId']], $this->audit()), '/QRIS belum diatur/');
        // Pesanan masuk kasir menampilkan pesanan yang menunggu dibayar (alarm ikut berbunyi); barista tidak.
        $outlet = ['id' => static::$fixture['A']['outletId']];
        $awaiting = collect(app(OrderEngine::class)->awaitingPayment($this->actor('cashierA'), $outlet))->firstWhere('id', $pickup['orderId']);
        $this->assertSame(['takeaway', 'Fundraising'], [$awaiting['type'] ?? null, $awaiting['customerDepartment'] ?? null]);
        $this->assertSame([], app(OrderEngine::class)->awaitingPayment($this->actor('baristaA'), $outlet));
        $cash = app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $pickup['orderId'], 'tenderedAmount' => 60_000], $this->audit());
        $this->assertNull(collect(app(OrderEngine::class)->awaitingPayment($this->actor('cashierA'), $outlet))->firstWhere('id', $pickup['orderId']), 'sudah dibayar: keluar dari daftar');
        $this->assertSame(10_000, $cash['change'], 'tunai: total 50.000 tanpa biaya antar');
    }
}
