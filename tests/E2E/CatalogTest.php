<?php

namespace Tests\E2E;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Tests\Live\CatalogTest as LiveCatalog;
use Tests\Live\LiveContext;

/** Padanan tests/e2e/catalog.test.ts: HTTP sungguhan, CSRF aktif, Storage sungguhan. */
class CatalogTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $company = $ctx->createCompany('e2ecat');
        // Meja resmi untuk QR meja (sejak daftar meja, EXT-026).
        DB::table('outlet_tables')->insert(['company_id' => $company['id'], 'outlet_id' => $company['outletId'], 'number' => 'T03']);

        return [
            'company' => $company,
            'admin' => $ctx->createUser($company, [['role' => 'super_admin']], true, 'E2E Admin Menu'),
            'manager' => $ctx->createUser($company, [['role' => 'manager', 'outlet' => 'outlet']], true, 'E2E Manager'),
            'barista' => $ctx->createUser($company, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista'),
        ];
    }

    private function f(string $key): array
    {
        return static::$fixture[$key];
    }

    /** POST multipart dengan token CSRF, seperti form produk di browser. */
    private function postMultipart(Browser $browser, string $path, array $fields, ?array $file = null)
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            foreach ((array) $value as $item) {
                $multipart[] = ['name' => is_array($value) ? "{$name}[]" : $name, 'contents' => (string) $item];
            }
        }
        if ($file !== null) {
            $multipart[] = ['name' => 'image', 'contents' => $file['contents'], 'filename' => $file['filename'], 'headers' => ['Content-Type' => $file['type']]];
        }
        $browser->get('/login');
        $client = new Client(['base_uri' => $this->base(), 'cookies' => $browser->jar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 60]);

        return $client->post($path, [
            'multipart' => $multipart,
            'headers' => ['X-XSRF-TOKEN' => urldecode((string) $browser->cookie('XSRF-TOKEN')), 'Accept' => 'text/html'],
        ]);
    }

    private function flash(Browser $browser, string $path): array
    {
        return Browser::page($browser->get($path))['flash'] ?? [];
    }

    private function menu(?Browser $guest = null): array
    {
        $page = Browser::page(($guest ?? $this->browser())->get('/order?outlet='.$this->f('company')['outletCode']));

        return $page['props']['storefront'] ?? [];
    }

    public function test_alur_katalog_dari_admin_sampai_menu_customer(): void
    {
        $company = $this->f('company');
        $admin = $this->login($this->f('admin'));
        $guest = $this->browser();

        // Guest: katalog kosong menampilkan empty state; outlet tak dikenal ditangani.
        $this->assertSame(200, $guest->get('/order?outlet='.$company['outletCode'])->getStatusCode());
        $this->assertSame([], $this->menu($guest)['products']);
        $this->assertSame('not_found', Browser::page($guest->get('/order?outlet=TIDAKADA'))['props']['notice']);

        // Admin membuat kategori dan grup modifier.
        $admin->post('/admin/categories', ['brandId' => $company['brandId'], 'values' => ['name' => 'Coffee', 'slug' => 'coffee', 'sortOrder' => 0, 'isActive' => true]]);
        $categoryId = DB::table('categories')->where('company_id', $company['id'])->value('id');
        $this->assertNotNull($categoryId);
        $admin->post('/admin/modifiers', ['brandId' => $company['brandId'], 'values' => [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1, 'sortOrder' => 0,
            'options' => [['id' => null, 'name' => 'Regular', 'priceDelta' => 0, 'isDefault' => true, 'isActive' => true], ['id' => null, 'name' => 'Large', 'priceDelta' => 6000, 'isDefault' => false, 'isActive' => true]],
        ]]);
        $groupId = DB::table('modifier_groups')->where('company_id', $company['id'])->value('id');

        // Admin membuat produk dengan foto sungguhan lewat multipart.
        $created = $this->postMultipart($admin, '/admin/products', [
            'brandId' => $company['brandId'], 'name' => 'Es Kopi Susu E2E', 'slug' => '', 'sku' => 'e2e-eks', 'categoryId' => $categoryId,
            'description' => 'Kopi susu gula aren', 'basePrice' => '22000', 'isActive' => 'true', 'modifierGroupIds' => [$groupId],
        ], ['contents' => base64_decode(LiveCatalog::TINY_PNG), 'filename' => 'kopi.png', 'type' => 'image/png']);
        $this->assertSame('/admin/products', Browser::location($created));
        $product = DB::table('products')->where('company_id', $company['id'])->first();
        $this->assertSame('es-kopi-susu-e2e', $product->slug);
        $this->assertSame('E2E-EKS', $product->sku);
        $this->assertNotNull($product->image_path);

        // Admin melihat produk di daftar dan halaman edit.
        $list = Browser::page($admin->get('/admin/products'));
        $this->assertSame('Es Kopi Susu E2E', $list['props']['list']['rows'][0]['name']);
        $edit = Browser::page($admin->get("/admin/products/{$product->id}/edit"));
        $this->assertSame([$groupId], $edit['props']['product']['modifierGroupIds']);

        // Guest melihat produk, harga, foto publik, dan nomor meja tanpa login.
        $order = Browser::page($guest->get("/order?outlet={$company['outletCode']}&table=T03"));
        $this->assertSame('T03', $order['props']['table']);
        $public = $order['props']['storefront']['products'][0];
        $this->assertSame(22000, $public['price']);
        $this->assertArrayNotHasKey('sku', $public);
        $this->assertSame(200, (new Client(['http_errors' => false]))->get($public['imageUrl'])->getStatusCode());

        // Quote dihitung server; harga palsu dari browser diabaikan.
        $large = collect($public['groups'][0]['modifiers'])->firstWhere('name', 'Large');
        $guest->get('/order?outlet='.$company['outletCode']);
        $quote = json_decode((string) (new Client(['base_uri' => $this->base(), 'cookies' => $guest->jar, 'http_errors' => false]))->post('/order/quote', [
            'json' => ['outlet' => $company['outletCode'], 'lines' => [['productId' => $product->id, 'modifierIds' => [$large['id']], 'quantity' => 2, 'unitPrice' => 1, 'total' => 1]], 'total' => 1],
            'headers' => ['X-XSRF-TOKEN' => urldecode((string) $guest->cookie('XSRF-TOKEN')), 'Accept' => 'application/json'],
        ])->getBody(), true);
        $this->assertSame((22000 + 6000) * 2, $quote['total']);

        // Manager menandai sold out: customer langsung melihat Sold out.
        $manager = $this->login($this->f('manager'));
        $manager->post("/admin/products/{$product->id}/availability", ['outletId' => $company['outletId'], 'isAvailable' => false], method: 'PATCH');
        $this->assertFalse($this->menu()['products'][0]['isAvailable']);
        $manager->post("/admin/products/{$product->id}/availability", ['outletId' => $company['outletId'], 'isAvailable' => true], method: 'PATCH');
        $this->assertTrue($this->menu()['products'][0]['isAvailable']);

        // Admin mengubah harga: audit price_changed dan menu customer ikut berubah.
        $this->postMultipart($admin, "/admin/products/{$product->id}", [
            'name' => 'Es Kopi Susu E2E', 'slug' => 'es-kopi-susu-e2e', 'sku' => 'E2E-EKS', 'categoryId' => $categoryId,
            'description' => 'Kopi susu gula aren', 'basePrice' => '24000', 'isActive' => 'true', 'modifierGroupIds' => [$groupId],
        ]);
        $this->assertSame(1, $this->ctx()->countAudit($company['id'], 'product.price_changed', $product->id));
        $this->assertSame(24000, $this->menu()['products'][0]['price']);

        // Validasi server menolak harga tidak valid tanpa menyimpan.
        $this->postMultipart($admin, "/admin/products/{$product->id}", [
            'name' => 'Es Kopi Susu E2E', 'categoryId' => $categoryId, 'basePrice' => '-1', 'isActive' => 'true',
        ]);
        $this->assertSame('Harga minimal 0.', $this->flash($admin, '/admin/products')['error'] ?? null);
        $this->assertSame(24000, (int) DB::table('products')->where('id', $product->id)->value('base_price'));

        // Produk dinonaktifkan hilang dari menu, aktif lagi muncul.
        $admin->post("/admin/products/{$product->id}/active", ['isActive' => false], method: 'PATCH');
        $this->assertSame([], $this->menu()['products']);
        $admin->post("/admin/products/{$product->id}/active", ['isActive' => true], method: 'PATCH');
        $this->assertCount(1, $this->menu()['products']);

        // Soft delete: hilang dari menu customer, baris tetap ada.
        $admin->post("/admin/products/{$product->id}", [], method: 'DELETE');
        $this->assertSame([], $this->menu()['products']);
        $this->assertNotNull(DB::table('products')->where('id', $product->id)->value('deleted_at'));
        $this->assertSame(404, $admin->get("/admin/products/{$product->id}/edit")->getStatusCode());
    }

    public function test_upload_file_berbahaya_ditolak_tanpa_menyimpan(): void
    {
        $company = $this->f('company');
        $admin = $this->login($this->f('admin'));
        $categoryId = DB::table('categories')->where('company_id', $company['id'])->whereNull('deleted_at')->value('id')
            ?? DB::table('categories')->insertGetId(['company_id' => $company['id'], 'brand_id' => $company['brandId'], 'name' => 'Food', 'slug' => 'food']);
        $fields = ['brandId' => $company['brandId'], 'name' => 'Jahat', 'categoryId' => $categoryId, 'basePrice' => '1000', 'isActive' => 'true'];

        foreach ([
            ['contents' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'filename' => 'foto.png', 'type' => 'image/png'],
            ['contents' => '<?php system($_GET["c"]); ?>', 'filename' => 'shell.php', 'type' => 'image/jpeg'],
            ['contents' => 'GIF89a'.str_repeat('x', 100), 'filename' => 'anim.gif', 'type' => 'image/gif'],
        ] as $file) {
            $this->postMultipart($admin, '/admin/products', $fields, $file);
            $this->assertSame('Format foto harus JPG, PNG, atau WebP.', $this->flash($admin, '/admin/products')['error'] ?? null, $file['filename']);
        }
        $this->assertFalse(DB::table('products')->where('company_id', $company['id'])->where('name', 'Jahat')->exists());
    }

    public function test_barista_ditolak_di_halaman_dan_aksi_katalog(): void
    {
        $company = $this->f('company');
        $barista = $this->login($this->f('barista'));
        foreach (['/admin/products', '/admin/products/create', '/admin/categories', '/admin/modifiers'] as $path) {
            $this->assertSame(403, $barista->get($path)->getStatusCode(), $path);
        }
        $this->assertSame(403, $barista->post('/admin/categories', ['brandId' => $company['brandId'], 'values' => ['name' => 'Ilegal']])->getStatusCode());
        $this->assertFalse(DB::table('categories')->where('company_id', $company['id'])->where('name', 'Ilegal')->exists());

        // Manager outlet boleh melihat, tapi tidak membuka form data master.
        $manager = $this->login($this->f('manager'));
        $this->assertSame(200, $manager->get('/admin/products')->getStatusCode());
        $this->assertSame(403, $manager->get('/admin/products/create')->getStatusCode());
    }

    public function test_quote_menolak_input_rusak_dan_post_tanpa_csrf(): void
    {
        $guest = $this->browser();
        $guest->get('/order');
        $client = new Client(['base_uri' => $this->base(), 'cookies' => $guest->jar, 'http_errors' => false]);
        $headers = ['X-XSRF-TOKEN' => urldecode((string) $guest->cookie('XSRF-TOKEN')), 'Accept' => 'application/json'];

        $bad = json_decode((string) $client->post('/order/quote', ['json' => ['outlet' => 'X', 'lines' => [['productId' => 'x', 'modifierIds' => [], 'quantity' => 999]]], 'headers' => $headers])->getBody(), true);
        $this->assertSame(['ok' => false, 'error' => 'Data keranjang tidak valid.'], $bad);
        $this->assertSame(419, $client->post('/order/quote', ['json' => ['outlet' => 'X', 'lines' => []], 'headers' => ['Accept' => 'application/json']])->getStatusCode());
    }
}
