<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
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
 * Pesanan meja kantor lewat HTTP sungguhan: admin mengatur biaya antar + mengunggah QRIS
 * (bucket publik asli) → pelanggan pesan dari QR meja (nama, lantai, bagian, catatan, request
 * di luar menu, QRIS) → kasir konfirmasi QRIS → masuk layar barista dengan nomor HP untuk WA.
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

    public function test_admin_atur_biaya_antar_dan_qris(): void
    {
        $outletId = $this->f('A')['outletId'];
        $admin = $this->login($this->f('admin'));
        $page = Browser::page($admin->get("/admin/outlets/{$outletId}"));
        $this->assertSame([0, null], [$page['props']['outlet']['deliveryFee'], $page['props']['outlet']['qrisImageUrl']]);

        $png = "\x89PNG\r\n\x1a\n".str_repeat('q', 256);
        $flash = $this->flash($admin, $admin->multipart("/admin/outlets/{$outletId}/ordering", ['deliveryFee' => '4000', 'removeQris' => '0'], ['qris' => ['name' => 'qris.png', 'bytes' => $png, 'type' => 'image/png']]));
        $this->assertSame('Pengaturan pesanan meja disimpan; QRIS aktif untuk pelanggan.', $flash['success'] ?? null);
        $page = Browser::page($admin->get("/admin/outlets/{$outletId}"));
        $this->assertSame(4000, $page['props']['outlet']['deliveryFee']);
        $this->assertNotNull($page['props']['outlet']['qrisImageUrl']);
        $this->assertSame(403, $this->login($this->f('cashier'))->get("/admin/outlets/{$outletId}")->getStatusCode());
    }

    public function test_pesan_dari_meja_kantor_bayar_qris_lalu_ke_barista(): void
    {
        $code = $this->f('A')['outletCode'];
        $guest = $this->browser();
        $menu = Browser::page($guest->get("/order?outlet={$code}&table=L2-07"));
        $this->assertSame([4000, true], [$menu['props']['storefront']['outlet']['deliveryFee'], $menu['props']['storefront']['outlet']['qrisAvailable']]);

        $response = $guest->json('/order/checkout', ['outlet' => $code, 'channel' => 'qr_table', 'checkout' => [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => 'L2-07', 'customerName' => 'Sari',
            'customerPhone' => '081298765432', 'floor' => '2', 'department' => 'HRD', 'paymentMethod' => 'qris',
            'customRequest' => 'Teh tarik 1 gelas',
            'lines' => [['productId' => $this->f('latte'), 'modifierIds' => [], 'quantity' => 2, 'notes' => 'Less sugar, Less ice']],
        ]]);
        $this->trackLimits($guest);
        $body = json_decode((string) $response->getBody(), true) ?? [];
        $this->assertSame([true, 52_000], [$body['ok'] ?? null, $body['total'] ?? null], (string) $response->getBody());

        $status = Browser::page($guest->get("/order/status/{$body['orderId']}"))['props']['order'];
        $this->assertSame(['qris', 4000, 'Lantai 2 · HRD', 52_000], [$status['paymentMethod'], $status['deliveryFee'], $status['locationNote'], $status['total']]);
        $this->assertNotNull($status['qrisImageUrl']);
        $this->assertStringContainsString('Permintaan di luar menu: Teh tarik 1 gelas', (string) DB::table('orders')->where('id', $body['orderId'])->value('notes'));

        $cashier = $this->login($this->f('cashier'));
        $detail = Browser::page($cashier->get("/pos/orders/{$body['orderId']}"))['props']['order'];
        $this->assertSame([4000, 48_000, 'qris'], [$detail['deliveryFee'], $detail['subtotal'], $detail['payments'][0]['method']]);
        // Barista tidak punya izin konfirmasi pembayaran: ditolak service (flash error), pesanan tetap belum lunas.
        $barista = $this->login($this->f('barista'));
        $barista->get('/kds');
        $this->assertNotEmpty($this->flash($barista, $barista->post("/pos/orders/{$body['orderId']}/confirm-qris"))['error'] ?? null);
        $this->assertSame('pending', DB::table('orders')->where('id', $body['orderId'])->value('payment_status'));
        $cashier->get("/pos/orders/{$body['orderId']}");
        $flash = $this->flash($cashier, $cashier->post("/pos/orders/{$body['orderId']}/confirm-qris"));
        $this->assertStringStartsWith('Pembayaran QRIS dikonfirmasi. Nomor antrean', $flash['success'] ?? '');

        $kds = Browser::page($barista->get('/kds'));
        $card = collect($kds['props']['orders'])->firstWhere('id', $body['orderId']);
        $this->assertNotNull($card, 'pesanan lunas tampil di layar barista');
        $this->assertSame(['qr_table', 'dine_in', 'L2-07', 'Lantai 2 · HRD', '081298765432'], [$card['channel'], $card['type'], $card['tableNumber'], $card['locationNote'], $card['customerPhone']]);

        $after = Browser::page($guest->get("/order/status/{$body['orderId']}"))['props']['order'];
        $this->assertSame(['queued', null], [$after['status'], $after['qrisImageUrl']]);
    }
}
