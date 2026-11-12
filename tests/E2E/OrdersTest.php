<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Services\Orders\CheckoutLimits;
use App\Services\Orders\GuestTokens;
use App\Services\Orders\OrderEngine;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Tests\Live\LiveContext;

/**
 * Padanan tests/e2e/orders.test.ts untuk lingkup M3: checkout guest lewat HTTP
 * sungguhan (CSRF, cookie, rate limit), halaman status, kiosk, dan POS.
 */
class OrdersTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('e2eord');
        $B = $ctx->createCompany('e2eordb');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Order'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Order'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir Order'),
            'owner' => $ctx->createUser($A, [['role' => 'owner']], true, 'E2E Owner Order'),
            'adminB' => $ctx->createUser($B, [['role' => 'super_admin']], true, 'E2E Admin B'),
        ];
        $admin = $ctx->load($users['admin']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $size = $catalog->createModifierGroup($admin, $A['brandId'], [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Regular', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Large', 'priceDelta' => 6000]],
        ], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Iced Latte', 'categoryId' => $coffee, 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => [$size]], null, $audit)['id'];
        $menu = app(Storefront::class)->load($A['outletCode']);
        $large = collect(collect($menu['products'])->firstWhere('id', $latte)['groups'][0]['modifiers'])->firstWhere('name', 'Large')['id'];
        // Order company B untuk uji isolasi.
        $adminB = $ctx->load($users['adminB']['id']);
        $teaB = $catalog->createProduct($adminB, $B['brandId'], [
            'name' => 'Iced Tea B', 'categoryId' => $catalog->createCategory($adminB, $B['brandId'], ['name' => 'Tea'], $audit)['id'],
            'basePrice' => '15000', 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];
        $orderB = app(OrderEngine::class)->create('pos', ['id' => $B['outletId']], $adminB, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'paymentMethod' => 'cash',
            'lines' => [['productId' => $teaB, 'modifierIds' => [], 'quantity' => 1]],
        ], $audit);

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $latte, 'large' => $large, 'orderB' => $orderB];
    }

    private function f(string $key): mixed
    {
        return static::$fixture[$key];
    }

    private function checkout(array $overrides = []): array
    {
        return [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'customerName' => 'Andi',
            'department' => 'Fundraising',
            'paymentMethod' => 'cash',
            'lines' => [['productId' => $this->f('latte'), 'modifierIds' => [$this->f('large')], 'quantity' => 2]],
            ...$overrides,
        ];
    }

    private function place(Browser $guest, array $checkout, string $channel = 'qr_table', array $headers = []): ResponseInterface
    {
        $response = $guest->json('/order/checkout', ['outlet' => $this->f('A')['outletCode'], 'channel' => $channel, 'checkout' => $checkout], $headers);
        $this->trackLimits($guest);

        return $response;
    }

    private static function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** Nilai cookie terenkripsi Laravel, dibuka dengan APP_KEY yang sama. */
    private static function plain(SetCookie $cookie): string
    {
        $value = Crypt::decryptString(urldecode($cookie->getValue()));

        return CookieValuePrefix::remove($value);
    }

    private static function setCookie(ResponseInterface $response, string $name): ?SetCookie
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = SetCookie::fromString($header);
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /** Kunci rate limit yang dipakai run ini dicatat agar ikut dibersihkan. */
    private function trackLimits(Browser $guest): void
    {
        $outlet = $this->f('A')['outletCode'];
        foreach (["checkout:outlet:{$outlet}", "checkout-replay:outlet:{$outlet}"] as $key) {
            $this->ctx()->trackRateLimitKey($key);
        }
        $device = $guest->jar->getCookieByName(CheckoutLimits::DEVICE_COOKIE);
        if ($device !== null) {
            $id = self::plain($device);
            $this->ctx()->trackRateLimitKey("checkout:device:{$id}");
            $this->ctx()->trackRateLimitKey("checkout-replay:device:{$id}");
        }
    }

    // --- checkout guest ----------------------------------------------------------------

    public function test_checkout_qr_cookie_token_aman_dan_status_hanya_dengan_cookie(): void
    {
        $guest = $this->browser();
        $input = $this->checkout([
            // Field harga/status dari browser diabaikan.
            'total' => 1, 'status' => 'paid', 'paymentStatus' => 'paid',
            'lines' => [['productId' => $this->f('latte'), 'modifierIds' => [$this->f('large')], 'quantity' => 2, 'unitPrice' => 1]],
        ]);
        $response = $this->place($guest, $input);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = self::body($response);
        $this->assertSame([true, 62000, OrderEngine::reference($body['orderId'])], [$body['ok'], $body['total'], $body['reference']]);
        $this->assertArrayNotHasKey('accessToken', $body, 'token hanya lewat cookie HttpOnly');

        $row = DB::table('orders')->where('id', $body['orderId'])->first();
        $this->assertSame(['pending_payment', 'pending', 62000, null], [$row->status, $row->payment_status, (int) $row->total, $row->queue_number]);

        // Cookie token: HttpOnly, path halaman status, SameSite Lax, 3 hari; isinya cocok dengan hash di DB.
        $token = self::setCookie($response, GuestTokens::cookieName($body['orderId']));
        $this->assertNotNull($token);
        $this->assertTrue($token->getHttpOnly());
        $this->assertSame(GuestTokens::COOKIE_PATH, $token->getPath());
        $this->assertStringContainsStringIgnoringCase('samesite=lax', implode(';', $response->getHeader('Set-Cookie')));
        $this->assertFalse($token->getSecure(), 'lokal tanpa HTTPS; production memaksa Secure');
        $this->assertEqualsWithDelta(time() + GuestTokens::COOKIE_MINUTES * 60, $token->getExpires(), 120);
        $this->assertSame($row->access_token_hash, GuestTokens::hash(self::plain($token)));
        $device = self::setCookie($response, CheckoutLimits::DEVICE_COOKIE);
        $this->assertTrue($device->getHttpOnly());

        // Halaman status dengan cookie: data publik saja, header anti-bocor.
        $status = $guest->get("/order/status/{$body['orderId']}");
        $this->assertSame(200, $status->getStatusCode());
        $this->assertSame('no-referrer', $status->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString('noindex', $status->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $status->getHeaderLine('Cache-Control'));
        $page = Browser::page($status);
        $this->assertSame('order/status', $page['component']);
        $this->assertSame([$body['reference'], 'pending_payment', 'pending', null], [$page['props']['order']['reference'], $page['props']['order']['status'], $page['props']['order']['paymentStatus'], $page['props']['order']['orderNumber']]);
        $this->assertStringNotContainsString($row->access_token_hash, (string) $status->getBody());
        $this->assertStringNotContainsString(self::plain($token), (string) $status->getBody());

        // Tanpa cookie (perangkat lain) atau dengan cookie order lain: tidak terlihat.
        $this->assertNull(Browser::page($this->browser()->get("/order/status/{$body['orderId']}"))['props']['order']);
        $other = self::body($this->place($guest, $this->checkout()));
        $stranger = $this->browser();
        $stranger->jar->setCookie(new SetCookie([
            'Name' => GuestTokens::cookieName($body['orderId']), 'Value' => $guest->jar->getCookieByName(GuestTokens::cookieName($other['orderId']))->getValue(),
            'Domain' => '127.0.0.1', 'Path' => '/order/status',
        ]));
        $this->assertNull(Browser::page($stranger->get("/order/status/{$body['orderId']}"))['props']['order']);
        $this->assertNull(Browser::page($guest->get('/order/status/bukan-uuid'))['props']['order']);

        // Retry (respons pertama hilang): 200, order sama, token baru menggantikan yang lama.
        $retry = $this->place($guest, $input);
        $this->assertSame(200, $retry->getStatusCode());
        $this->assertSame($body['orderId'], self::body($retry)['orderId']);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $input['idempotencyKey'])->count());
        $this->assertSame('pending_payment', Browser::page($guest->get("/order/status/{$body['orderId']}"))['props']['order']['status']);
    }

    public function test_kiosk_tidak_menyimpan_token_di_perangkat_bersama(): void
    {
        $kiosk = $this->browser();
        $page = Browser::page($kiosk->get('/kiosk?outlet='.$this->f('A')['outletCode']));
        $this->assertSame('kiosk/index', $page['component']);
        $this->assertSame([$this->f('latte')], array_column($page['props']['storefront']['products'], 'id'));
        $this->assertSame('unconfigured', Browser::page($kiosk->get('/kiosk'))['props']['notice']);
        $this->assertSame('not_found', Browser::page($kiosk->get('/kiosk?outlet=TIDAKADA'))['props']['notice']);

        $response = $this->place($kiosk, $this->checkout(['type' => 'dine_in']), 'kiosk');
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = self::body($response);
        $this->assertNull(self::setCookie($response, GuestTokens::cookieName($body['orderId'])));
        $this->assertSame(['kiosk', 'pending_payment'], [DB::table('orders')->where('id', $body['orderId'])->value('channel'), DB::table('orders')->where('id', $body['orderId'])->value('status')]);
        $this->assertNull(Browser::page($kiosk->get("/order/status/{$body['orderId']}"))['props']['order']);
    }

    public function test_respons_error_checkout(): void
    {
        $guest = $this->browser();
        $outlet = $this->f('A')['outletCode'];

        $conflictInput = $this->checkout();
        $this->assertSame(201, $this->place($guest, $conflictInput)->getStatusCode());
        $conflict = $this->place($guest, [...$conflictInput, 'customerName' => 'Sari']);
        $this->assertSame([409, OrderEngine::CONFLICT_MESSAGE], [$conflict->getStatusCode(), self::body($conflict)['error']]);

        $invalid = $this->place($guest, $this->checkout(['customerName' => null]));
        $this->assertSame([422, 'Nama pemesan wajib diisi (min. 2 karakter).'], [$invalid->getStatusCode(), self::body($invalid)['error']]);
        $qris = $this->place($guest, $this->checkout(['paymentMethod' => 'qris']));
        // QRIS hanya bila outlet mengunggah QRIS statisnya; outlet test belum punya → ditolak.
        $this->assertSame([422, 'QRIS belum tersedia di outlet ini. Silakan pilih tunai.'], [$qris->getStatusCode(), self::body($qris)['error']]);
        $closed = $guest->json('/order/checkout', ['outlet' => 'TIDAKADA', 'channel' => 'website', 'checkout' => $this->checkout()]);
        $this->assertSame(422, $closed->getStatusCode());
        $this->ctx()->trackRateLimitKey('checkout:outlet:TIDAKADA');

        // Channel POS tidak bisa dipilih dari endpoint publik.
        $pos = $guest->json('/order/checkout', ['outlet' => $outlet, 'channel' => 'pos', 'checkout' => $this->checkout()]);
        $this->assertSame(400, $pos->getStatusCode());
        $this->assertSame(400, $guest->json('/order/checkout', '{rusak')->getStatusCode());
        $this->assertSame(415, $guest->json('/order/checkout', 'outlet=X', ['Content-Type' => 'application/x-www-form-urlencoded'])->getStatusCode());
        $big = $guest->json('/order/checkout', ['outlet' => $outlet, 'channel' => 'website', 'checkout' => $this->checkout(['notes' => str_repeat('a', 70 * 1024)])]);
        $this->assertSame(413, $big->getStatusCode());
        // Tanpa token CSRF ditolak middleware sebelum controller.
        $this->assertSame(419, $guest->json('/order/checkout', ['outlet' => $outlet, 'channel' => 'website', 'checkout' => $this->checkout()], withCsrf: false)->getStatusCode());
        // Origin asing ditolak (bukan form dari situs lain).
        $evil = $guest->json('/order/checkout', ['outlet' => $outlet, 'channel' => 'website', 'checkout' => $this->checkout()], ['Origin' => 'https://evil.example']);
        $this->assertSame([403, 'Permintaan ditolak.'], [$evil->getStatusCode(), self::body($evil)['error']]);
        $this->assertSame(403, $guest->json('/order/checkout', ['outlet' => $outlet, 'channel' => 'website', 'checkout' => $this->checkout()], ['Origin' => '', 'Sec-Fetch-Site' => 'cross-site'])->getStatusCode());
        $this->assertSame(403, $guest->json('/order/checkout', ['outlet' => $outlet, 'channel' => 'website', 'checkout' => $this->checkout()], ['Origin' => 'null'])->getStatusCode());

        // Guest tidak bisa membuat order lunas. Sejak M4 endpoint bayar ada, tetapi khusus staf berizin.
        $order = self::body($this->place($guest, $this->checkout()))['orderId'];
        foreach (["/order/status/{$order}" => 405, "/pos/orders/{$order}/pay" => 401, '/order/checkout/pay' => 404] as $path => $status) {
            $this->assertSame($status, $guest->json($path, ['status' => 'paid', 'tenderedAmount' => 100000])->getStatusCode(), $path);
        }
        $this->assertSame(['pending_payment', 'pending'], array_values((array) DB::table('orders')->where('id', $order)->first(['status', 'payment_status'])));
        $this->assertSame(0, DB::table('payments')->where('order_id', $order)->where('status', 'paid')->count());
    }

    public function test_rate_limit_per_perangkat_429_dan_retry_tetap_dilayani(): void
    {
        $guest = $this->browser();
        $first = $this->checkout();
        $this->assertSame(201, $this->place($guest, $first)->getStatusCode());
        for ($i = 1; $i < CheckoutLimits::DEVICE; $i++) {
            $this->assertSame(201, $this->place($guest, $this->checkout())->getStatusCode(), "checkout ke-{$i}");
        }
        $limited = $this->place($guest, $this->checkout());
        $this->assertSame(429, $limited->getStatusCode());
        $retryAfter = (int) $limited->getHeaderLine('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(CheckoutLimits::WINDOW_SECONDS, $retryAfter);
        $this->assertSame($retryAfter, self::body($limited)['retryAfter']);
        $this->assertStringContainsString('Terlalu banyak pesanan', self::body($limited)['error']);

        // Retry order yang sudah ada tidak memakai kuota checkout baru.
        $this->assertSame(200, $this->place($guest, $first)->getStatusCode());
        // Perangkat lain di jaringan yang sama tetap bisa memesan.
        $this->assertSame(201, $this->place($this->browser(), $this->checkout())->getStatusCode());
    }

    // --- POS ---------------------------------------------------------------------------

    public function test_pos_kasir_membuat_order_pending_dan_melihat_struk(): void
    {
        // Keputusan owner 12 November 2026: pesanan lewat kasir; barista hanya absen.
        $this->assertSame(403, $this->login($this->f('barista'))->get('/pos')->getStatusCode());
        $barista = $this->login($this->f('cashier'));
        $page = Browser::page($barista->get('/pos'));
        $this->assertSame('pos/index', $page['component']);
        $this->assertSame($this->f('A')['outletId'], $page['props']['outlet']['id']);
        $this->assertSame([$this->f('latte')], array_column($page['props']['products'], 'id'));

        $input = $this->checkout(['customerName' => null, 'total' => 1, 'status' => 'paid']);
        $created = $barista->post('/pos/orders', ['outletId' => $this->f('A')['outletId'], 'checkout' => $input]);
        $this->assertSame(302, $created->getStatusCode());
        $flash = Browser::page($barista->get(Browser::location($created)))['flash'] ?? [];
        $this->assertArrayHasKey('placed', $flash, json_encode($flash));
        $orderId = $flash['placed']['orderId'];
        $this->assertSame([62000, false], [$flash['placed']['total'], $flash['placed']['replayed']]);
        $row = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame(['pos', $this->f('cashier')['id'], 'pending_payment', 'pending', null, null], [$row->channel, $row->created_by, $row->status, $row->payment_status, $row->access_token_hash, $row->queue_number]);

        // Double submit: order sama.
        $again = $barista->post('/pos/orders', ['outletId' => $this->f('A')['outletId'], 'checkout' => $input]);
        $this->assertTrue((Browser::page($barista->get(Browser::location($again)))['flash']['placed'] ?? [])['replayed'] ?? false);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $input['idempotencyKey'])->count());

        // Error validasi tampil sebagai flash, tanpa 500.
        $empty = $barista->post('/pos/orders', ['outletId' => $this->f('A')['outletId'], 'checkout' => $this->checkout(['lines' => []])]);
        $this->assertSame(302, $empty->getStatusCode());
        $this->assertSame('Keranjang masih kosong.', Browser::page($barista->get(Browser::location($empty)))['flash']['error'] ?? null);

        $list = Browser::page($barista->get('/pos/orders?view=pending'));
        $this->assertSame('pos/orders/index', $list['component']);
        $this->assertContains($orderId, array_column($list['props']['list']['rows'], 'id'));
        $search = Browser::page($barista->get('/pos/orders?view=today&q='.OrderEngine::reference($orderId)));
        $this->assertSame([$orderId], array_column($search['props']['list']['rows'], 'id'));

        $detail = Browser::page($barista->get("/pos/orders/{$orderId}"));
        $this->assertSame(['pos/orders/show', 'pending_payment'], [$detail['component'], $detail['props']['order']['status']]);
        $this->assertArrayNotHasKey('accessTokenHash', $detail['props']['order']);
        $receipt = $barista->get("/pos/orders/{$orderId}/receipt");
        $this->assertSame(['pos/orders/receipt', 62000], [Browser::page($receipt)['component'], Browser::page($receipt)['props']['order']['total']]);

        // Order company lain dan id rusak: 404 tanpa bocor info.
        $this->assertSame(404, $barista->get('/pos/orders/'.$this->f('orderB')['orderId'])->getStatusCode());
        $this->assertSame(404, $barista->get('/pos/orders/'.$this->f('orderB')['orderId'].'/receipt')->getStatusCode());
        $this->assertSame(404, $barista->get('/pos/orders/bukan-uuid')->getStatusCode());
    }

    public function test_pos_hanya_untuk_staf_dengan_izin_order_create(): void
    {
        $this->assertSame('/login', parse_url(Browser::location($this->browser()->get('/pos')), PHP_URL_PATH));
        $guestPost = $this->browser()->post('/pos/orders', ['outletId' => $this->f('A')['outletId'], 'checkout' => $this->checkout()]);
        $this->assertSame('/login', parse_url(Browser::location($guestPost), PHP_URL_PATH));

        $owner = $this->login($this->f('owner'));
        $this->assertSame(403, $owner->get('/pos')->getStatusCode());
        $this->assertSame(403, $owner->get('/pos/orders')->getStatusCode());
        $this->assertContains($owner->post('/pos/orders', ['outletId' => $this->f('A')['outletId'], 'checkout' => $this->checkout()])->getStatusCode(), [302, 403]);
        $this->assertSame(0, DB::table('orders')->where('company_id', $this->f('A')['id'])->whereNotNull('created_by')->where('created_by', $this->f('owner')['id'])->count());

        // Admin company B tidak bisa membuat order di outlet company A lewat POS.
        $adminB = $this->login($this->f('adminB'));
        $cross = $adminB->post('/pos/orders', ['outletId' => $this->f('A')['outletId'], 'checkout' => $this->checkout()]);
        $this->assertSame(302, $cross->getStatusCode());
        $this->assertSame(0, DB::table('orders')->where('company_id', $this->f('A')['id'])->where('created_by', $this->f('adminB')['id'])->count());
    }
}
