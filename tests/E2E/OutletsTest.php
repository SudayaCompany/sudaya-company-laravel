<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Live\LiveContext;

/** Pengaturan outlet dan meja lewat HTTP sungguhan, serta QR meja di halaman customer. */
class OutletsTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('e2eout');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Outlet'),
            'manager' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']], true, 'E2E Manager Outlet'),
        ];
        $admin = $ctx->load($users['admin']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $tea = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Iced Tea', 'categoryId' => $cat, 'basePrice' => '15000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];

        return ['A' => $A, ...$users, 'tea' => $tea];
    }

    private function f(string $key): mixed
    {
        return static::$fixture[$key];
    }

    private function act(Browser $browser, string $method, string $path, array $data, string $from): array
    {
        $browser->get($from);
        $response = $browser->post($path, $data, method: $method);
        $this->assertSame(302, $response->getStatusCode(), "{$method} {$path}");

        return Browser::page($browser->get(Browser::location($response)))['flash'] ?? [];
    }

    private function qrCheckout(string $table): array
    {
        $guest = $this->browser();
        $this->ctx()->trackRateLimitKey('checkout:outlet:'.$this->f('A')['outletCode']);
        $response = $guest->json('/order/checkout', ['outlet' => $this->f('A')['outletCode'], 'channel' => 'qr_table', 'checkout' => [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => $table, 'customerName' => 'Tamu', 'department' => 'Fundraising', 'floor' => '1', 'area' => 'Depan', 'paymentMethod' => 'cash',
            'lines' => [['productId' => $this->f('tea'), 'modifierIds' => [], 'quantity' => 1]],
        ]]);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    public function test_admin_mengatur_outlet_dan_meja_lalu_qr_mengikuti(): void
    {
        $outletId = $this->f('A')['outletId'];
        $code = $this->f('A')['outletCode'];
        $admin = $this->login($this->f('admin'));

        $list = Browser::page($admin->get('/admin/outlets'));
        $this->assertSame('admin/outlets/index', $list['component']);
        $this->assertContains($outletId, array_column($list['props']['outlets'], 'id'));
        $this->assertContains('/admin/outlets', $list['props']['auth']['navigation']);

        $edit = Browser::page($admin->get("/admin/outlets/{$outletId}"))['props'];
        $flash = $this->act($admin, 'PUT', "/admin/outlets/{$outletId}", [
            'name' => 'Outlet E2E Baru', 'address' => 'Jl. E2E 1', 'orderNumberPrefix' => 'eto', 'pendingPaymentTtlMinutes' => 30, 'isActive' => true,
        ], "/admin/outlets/{$outletId}");
        $this->assertSame('Pengaturan outlet disimpan.', $flash['success'] ?? null, json_encode($flash));
        $this->assertSame(['Outlet E2E Baru', 'ETO', 30], array_values((array) DB::table('outlets')->where('id', $outletId)->first(['name', 'order_number_prefix', 'pending_payment_ttl_minutes'])));
        $this->assertNotSame($edit['outlet']['name'], 'Outlet E2E Baru');
        // Validasi server tampil sebagai pesan, data tidak berubah.
        $flash = $this->act($admin, 'PUT', "/admin/outlets/{$outletId}", ['name' => 'X', 'orderNumberPrefix' => 'ETO', 'pendingPaymentTtlMinutes' => 30, 'isActive' => true], "/admin/outlets/{$outletId}");
        $this->assertSame('Nama outlet minimal 2 karakter.', $flash['error'] ?? null);

        $flash = $this->act($admin, 'POST', "/admin/outlets/{$outletId}/tables", ['numbers' => 't01, t02'], "/admin/outlets/{$outletId}");
        $this->assertSame('2 meja ditambahkan.', $flash['success'] ?? null);
        $flash = $this->act($admin, 'POST', "/admin/outlets/{$outletId}/tables", ['numbers' => 'T02'], "/admin/outlets/{$outletId}");
        $this->assertSame('Semua nomor meja sudah terdaftar.', $flash['success'] ?? null);
        $t02 = collect(Browser::page($admin->get("/admin/outlets/{$outletId}"))['props']['tables'])->firstWhere('number', 'T02')['id'];
        $flash = $this->act($admin, 'PATCH', "/admin/outlet-tables/{$t02}", ['isActive' => false], "/admin/outlets/{$outletId}");
        $this->assertSame('Meja T02 dinonaktifkan.', $flash['success'] ?? null);

        // Lembar QR siap cetak: hanya meja aktif (checkout QR menolak meja nonaktif).
        $qr = Browser::page($admin->get("/admin/outlets/{$outletId}/qr"));
        $this->assertSame('admin/outlets/qr', $qr['component']);
        $this->assertSame($code, $qr['props']['outlet']['code']);
        $this->assertSame(['T01'], array_column($qr['props']['tables'], 'number'));

        // Halaman QR: meja aktif membuka menu; meja nonaktif/tidak terdaftar ditolak dengan pesan.
        $guest = $this->browser();
        $page = Browser::page($guest->get("/order?outlet={$code}&table=t01"));
        $this->assertSame(['T01', 'qr_table', 'Outlet E2E Baru'], [$page['props']['table'], $page['props']['channel'], $page['props']['storefront']['outlet']['name']]);
        foreach (['T02', 'T99', 'T 01'] as $table) {
            $props = Browser::page($guest->get("/order?outlet={$code}&table=".rawurlencode($table)))['props'];
            $this->assertSame('table_unknown', $props['notice'] ?? null, $table);
            $this->assertArrayNotHasKey('storefront', $props);
        }
        // Tanpa meja: menu biasa tetap terbuka.
        $this->assertArrayHasKey('storefront', Browser::page($guest->get("/order?outlet={$code}"))['props']);

        // Checkout QR meja diperiksa ulang di server.
        $this->assertSame(201, $this->qrCheckout('T01')[0]);
        [$status, $body] = $this->qrCheckout('T02');
        $this->assertSame([422, 'Meja T02 tidak terdaftar atau sedang tidak aktif. Silakan tanyakan kasir.'], [$status, $body['error']]);
    }

    public function test_manager_tanpa_settings_manage_ditolak(): void
    {
        $outletId = $this->f('A')['outletId'];
        $manager = $this->login($this->f('manager'));
        $this->assertSame(403, $manager->get('/admin/outlets')->getStatusCode());
        $this->assertSame(403, $manager->get("/admin/outlets/{$outletId}")->getStatusCode());
        $this->assertSame(403, $manager->get("/admin/outlets/{$outletId}/qr")->getStatusCode());
        $this->assertSame(403, $manager->post("/admin/outlets/{$outletId}", ['name' => 'Bajak'], method: 'PUT')->getStatusCode());
        $this->assertSame(403, $manager->post("/admin/outlets/{$outletId}/tables", ['numbers' => 'Z1'])->getStatusCode());
        $this->assertSame(0, DB::table('outlet_tables')->where('outlet_id', $outletId)->where('number', 'Z1')->count());
        // Tanpa CSRF ditolak sebelum aksi.
        $admin = $this->login($this->f('admin'));
        $this->assertSame(419, $admin->post("/admin/outlets/{$outletId}/tables", ['numbers' => 'Z2'], withCsrf: false)->getStatusCode());
        $this->assertSame(0, DB::table('outlet_tables')->where('outlet_id', $outletId)->where('number', 'Z2')->count());
    }
}
