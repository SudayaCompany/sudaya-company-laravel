<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\CheckoutLimits;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Tests\Live\LiveContext;

/**
 * Pesanan meja kantor lewat HTTP sungguhan: admin mengunggah QRIS (biaya antar dihapus 11 November
 * 2026) → pelanggan pesan dari QR meja (nama, lantai, bagian, catatan, request di luar menu):
 * QRIS dikonfirmasi kasir; antar ke meja + tunai langsung masuk Pesanan masuk kasir dan kasir
 * menerima uang di meja (bayar saat diantar). Sejak 12 November 2026 barista hanya absen.
 */
class DeskFlowTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('e2edesk');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Meja'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir Meja'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Meja'),
        ];
        $admin = $ctx->load($users['admin']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Latte Kantor', 'categoryId' => $cat, 'basePrice' => '24000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'L2-07']);
        // Laci kas outlet terbuka: uang tunai yang diterima barista dicatat di sini.
        app(CashSessions::class)->open($ctx->load($users['cashier']['id']), ['outletId' => $A['outletId'], 'openingAmount' => 100_000], $audit);

        return ['A' => $A, ...$users, 'latte' => $latte];
    }

    private function f(string $key): mixed
    {
        return static::$fixture[$key];
    }

    private function flash(Browser $browser, ResponseInterface $response): array
    {
        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        return Browser::page($browser->get(Browser::location($response)))['flash'] ?? [];
    }

    /** Kunci rate limit checkout milik run ini, agar dibersihkan. */
    private function trackLimits(Browser $guest): void
    {
        $outlet = $this->f('A')['outletCode'];
        foreach (["checkout:outlet:{$outlet}", "checkout-replay:outlet:{$outlet}"] as $key) {
            $this->ctx()->trackRateLimitKey($key);
        }
        $device = $guest->jar->getCookieByName(CheckoutLimits::DEVICE_COOKIE);
        if ($device instanceof SetCookie) {
            $id = CookieValuePrefix::remove(Crypt::decryptString(urldecode($device->getValue())));
            $this->ctx()->trackRateLimitKey("checkout:device:{$id}");
            $this->ctx()->trackRateLimitKey("checkout-replay:device:{$id}");
        }
    }

    private function checkout(Browser $guest, array $overrides): array
    {
        $response = $guest->json('/order/checkout', ['outlet' => $this->f('A')['outletCode'], 'channel' => 'qr_table', 'checkout' => [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => 'L2-07', 'customerName' => 'Sari',
            'customerPhone' => '081298765432', 'floor' => '2', 'area' => 'HRD', 'department' => 'Fundraising', 'paymentMethod' => 'qris',
            'customRequest' => 'Teh tarik 1 gelas',
            'lines' => [['productId' => $this->f('latte'), 'modifierIds' => [], 'quantity' => 2, 'notes' => 'Less sugar, Less ice']],
            ...$overrides,
        ]]);
        $this->trackLimits($guest);
        $body = json_decode((string) $response->getBody(), true) ?? [];
        $this->assertSame([true, 48_000], [$body['ok'] ?? null, $body['total'] ?? null], (string) $response->getBody());

        return $body;
    }

    public function test_admin_unggah_qris_tanpa_biaya_antar(): void
    {
        $outletId = $this->f('A')['outletId'];
        $admin = $this->login($this->f('admin'));
        $page = Browser::page($admin->get("/admin/outlets/{$outletId}"));
        $this->assertNull($page['props']['outlet']['qrisImageUrl']);
        $this->assertArrayNotHasKey('deliveryFee', $page['props']['outlet']);

        // Payload persis seperti browser (FormData: boolean menjadi "0").
        $png = "\x89PNG\r\n\x1a\n".str_repeat('q', 256);
        $flash = $this->flash($admin, $admin->multipart("/admin/outlets/{$outletId}/ordering", ['removeQris' => '0'], ['qris' => ['name' => 'qris.png', 'bytes' => $png, 'type' => 'image/png']]));
        $this->assertSame('QRIS outlet disimpan; QRIS aktif untuk pelanggan.', $flash['success'] ?? null);
        $this->assertNotNull(Browser::page($admin->get("/admin/outlets/{$outletId}"))['props']['outlet']['qrisImageUrl']);
        $this->assertSame(403, $this->login($this->f('cashier'))->get("/admin/outlets/{$outletId}")->getStatusCode());
    }

    public function test_pesan_qris_dari_meja_dikonfirmasi_barista(): void
    {
        $guest = $this->browser();
        $menu = Browser::page($guest->get("/order?outlet={$this->f('A')['outletCode']}&table=L2-07"));
        $this->assertTrue($menu['props']['storefront']['outlet']['qrisAvailable']);
        $this->assertArrayNotHasKey('deliveryFee', $menu['props']['storefront']['outlet']);

        $body = $this->checkout($guest, []);
        $status = Browser::page($guest->get("/order/status/{$body['orderId']}"))['props']['order'];
        $this->assertSame(['qris', false, 'Lantai 2 · HRD', 48_000, 'pending_payment'], [$status['paymentMethod'], $status['payOnDelivery'], $status['locationNote'], $status['total'], $status['status']]);
        $this->assertNotNull($status['qrisImageUrl']);
        $this->assertStringContainsString('Permintaan di luar menu: Teh tarik 1 gelas', (string) DB::table('orders')->where('id', $body['orderId'])->value('notes'));

        // Barista (sejak 11 November 2026) boleh menandai QRIS diterima setelah cek dana.
        $barista = $this->login($this->f('cashier'));
        $barista->get("/pos/orders/{$body['orderId']}");
        $flash = $this->flash($barista, $barista->post("/pos/orders/{$body['orderId']}/confirm-qris"));
        $this->assertStringStartsWith('Pembayaran QRIS dikonfirmasi. Nomor antrean', $flash['success'] ?? '');

        $kds = Browser::page($barista->get('/kds'));
        $card = collect($kds['props']['orders'])->firstWhere('id', $body['orderId']);
        $this->assertNotNull($card, 'pesanan lunas tampil di layar barista');
        $this->assertSame(['qr_table', 'dine_in', 'L2-07', 'Lantai 2 · HRD', '081298765432', 'paid', false], [$card['channel'], $card['type'], $card['tableNumber'], $card['locationNote'], $card['customerPhone'], $card['paymentStatus'], $card['payOnDelivery']]);
        $this->assertTrue($kds['props']['canTakePayment']);

        $after = Browser::page($guest->get("/order/status/{$body['orderId']}"))['props']['order'];
        $this->assertSame(['queued', null], [$after['status'], $after['qrisImageUrl']]);
    }

    public function test_antar_ke_meja_tunai_dibayar_ke_kasir_saat_diantar(): void
    {
        $guest = $this->browser();
        $guest->get("/order?outlet={$this->f('A')['outletCode']}&table=L2-07");
        $body = $this->checkout($guest, ['paymentMethod' => 'cash', 'customRequest' => null]);

        $status = Browser::page($guest->get("/order/status/{$body['orderId']}"))['props']['order'];
        $this->assertSame(['queued', 'pending', true], [$status['status'], $status['paymentStatus'], $status['payOnDelivery']]);
        $this->assertNotNull($status['orderNumber'], 'nomor antrean langsung terbit');

        $barista = $this->login($this->f('cashier'));
        $kds = Browser::page($barista->get('/kds'));
        $card = collect($kds['props']['orders'])->firstWhere('id', $body['orderId']);
        $this->assertSame(['queued', 'pending', true, 48_000], [$card['status'], $card['paymentStatus'], $card['payOnDelivery'], $card['total']]);

        foreach ([['queued', 'preparing'], ['preparing', 'ready']] as [$from, $to]) {
            $barista->get('/kds');
            $this->flash($barista, $barista->post("/kds/orders/{$body['orderId']}/status", ['from' => $from, 'to' => $to]));
        }
        $barista->get('/kds');
        $blocked = $this->flash($barista, $barista->post("/kds/orders/{$body['orderId']}/status", ['from' => 'ready', 'to' => 'completed']));
        $this->assertStringContainsString('Terima pembayaran dulu', $blocked['error'] ?? '');

        // Detail pesanan untuk kasir menampilkan form terima tunai; kasir juga yang membuka kas.
        $detail = Browser::page($barista->get("/pos/orders/{$body['orderId']}"));
        $this->assertTrue($detail['props']['flags']['canConfirmCash']);
        $this->assertTrue($detail['props']['flags']['canOpenCash']);
        // Akun barista (absen saja) tidak bisa membuka maupun memproses pesanan.
        $onlyAttendance = $this->login($this->f('barista'));
        $this->assertSame(403, $onlyAttendance->get("/pos/orders/{$body['orderId']}")->getStatusCode());
        $this->assertSame(403, $onlyAttendance->post("/pos/orders/{$body['orderId']}/pay", ['tenderedAmount' => 50_000])->getStatusCode());
        $this->assertTrue($detail['props']['order']['payOnDelivery']);
        $flash = $this->flash($barista, $barista->post("/pos/orders/{$body['orderId']}/pay", ['tenderedAmount' => 50_000]));
        $this->assertNotEmpty($flash['success'] ?? null, json_encode($flash));

        $row = DB::table('orders')->where('id', $body['orderId'])->first(['status', 'payment_status']);
        $this->assertSame(['ready', 'paid'], [$row->status, $row->payment_status]);
        $payment = DB::table('payments')->where('order_id', $body['orderId'])->first(['method', 'status', 'tendered_amount', 'change_amount', 'cash_session_id']);
        $this->assertSame(['cash', 'paid', 50_000, 2_000], [$payment->method, $payment->status, (int) $payment->tendered_amount, (int) $payment->change_amount]);
        $this->assertNotNull($payment->cash_session_id);

        $barista->get('/kds');
        $this->flash($barista, $barista->post("/kds/orders/{$body['orderId']}/status", ['from' => 'ready', 'to' => 'completed']));
        $this->assertSame('completed', DB::table('orders')->where('id', $body['orderId'])->value('status'));
    }
}
