<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Inventory\InventoryAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Tests\Live\LiveContext;

/**
 * Fase 2 lewat HTTP sungguhan: pengeluaran (+ foto nota ke bucket privat asli) → Persetujuan,
 * supplier → purchase order → disetujui → terima barang → stok, dan resep/BOM; batas akses.
 */
class Phase2FlowTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('e2ep2');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin F2'),
            'owner' => $ctx->createUser($A, [['role' => 'owner']], true, 'E2E Owner F2'),
            'manager' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']], true, 'E2E Manager F2'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir F2'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista F2'),
        ];
        $admin = $ctx->load($users['admin']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Latte F2', 'categoryId' => $coffee, 'basePrice' => '28000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        $beans = app(InventoryAdmin::class)->createItem($admin, ['outletId' => $A['outletId'], 'sku' => 'BIJI-F2', 'name' => 'Biji F2', 'kind' => 'raw_material', 'unit' => 'g', 'minimum' => '0', 'openingStock' => '500', 'purchasePrice' => '', 'expiryDate' => '', 'category' => 'Kopi'], $audit);

        return ['A' => $A, ...$users, 'latte' => $latte, 'beans' => $beans];
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

    public function test_pengeluaran_dengan_nota_lalu_disetujui(): void
    {
        $outletId = $this->f('A')['outletId'];
        $cashier = $this->login($this->f('cashier'));
        $page = Browser::page($cashier->get('/admin/expenses'));
        $this->assertSame('admin/expenses/index', $page['component']);
        $this->assertContains('/admin/expenses', $page['props']['auth']['navigation']);
        $this->assertTrue($page['props']['report']['canSubmit']);
        $this->assertFalse($page['props']['report']['canReview']);

        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $today = CarbonImmutable::now(DB::table('outlets')->where('id', $outletId)->value('timezone'))->toDateString();
        $flash = $this->flash($cashier, $cashier->multipart('/admin/expenses', ['outletId' => $outletId, 'expenseDate' => $today, 'category' => 'parkir', 'amount' => '5000', 'description' => 'Parkir belanja E2E'], ['photo' => ['name' => 'nota.jpg', 'bytes' => $jpeg, 'type' => 'image/jpeg']]));
        $this->assertSame('Pengeluaran tercatat dan menunggu persetujuan Manager atau Owner.', $flash['success'] ?? null);

        $owner = $this->login($this->f('owner'));
        $inbox = Browser::page($owner->get('/admin/approvals'));
        $this->assertCount(1, $inbox['props']['expenses']);
        $expense = $inbox['props']['expenses'][0];
        $this->assertSame([5000, 'Parkir', 'E2E Kasir F2', true], [$expense['amount'], $expense['categoryLabel'], $expense['requestedByName'], $expense['canDecide']]);
        $image = $owner->get($expense['evidenceUrl']);
        $this->assertSame([200, 'image/jpeg'], [$image->getStatusCode(), $image->getHeaderLine('Content-Type')]);
        $this->assertStringContainsString('no-store', $image->getHeaderLine('Cache-Control'));
        $this->assertSame(404, $this->login($this->f('barista'))->get($expense['evidenceUrl'])->getStatusCode(), 'staf lain tidak melihat nota');

        $owner->get('/admin/approvals');
        $flash = $this->flash($owner, $owner->post("/admin/approvals/{$expense['id']}/decide", ['approve' => true]));
        $this->assertSame('Pengeluaran disetujui.', $flash['success'] ?? null);
        $list = Browser::page($owner->get('/admin/expenses'))['props']['report'];
        $this->assertSame(['approved' => 5000, 'pending' => 0, 'count' => 1], $list['totals']);
        $this->assertSame(5000, Browser::page($owner->get('/admin/reports'))['props']['report']['summary']['expenses']);

        $guest = $this->browser()->get('/admin/expenses');
        $this->assertSame(302, $guest->getStatusCode());
        $this->assertStringStartsWith('/login', Browser::location($guest));
    }

    public function test_supplier_purchase_order_sampai_barang_diterima(): void
    {
        $outletId = $this->f('A')['outletId'];
        $manager = $this->login($this->f('manager'));
        $manager->get('/admin/suppliers');
        $flash = $this->flash($manager, $manager->post('/admin/suppliers', ['name' => 'CV Biji E2E', 'phone' => '0811111111', 'paymentTerm' => 'Tunai', 'isActive' => true]));
        $this->assertSame('Supplier ditambahkan.', $flash['success'] ?? null);
        $supplier = Browser::page($manager->get('/admin/suppliers'))['props']['suppliers'][0];
        $this->assertSame('CV Biji E2E', $supplier['name']);

        $form = Browser::page($manager->get("/admin/purchase-orders/create?outlet={$outletId}"));
        $this->assertSame('admin/purchasing/order-form', $form['component']);
        $this->assertSame(['Biji F2'], array_column($form['props']['items'], 'name'));
        $created = $manager->post('/admin/purchase-orders', ['outletId' => $outletId, 'supplierId' => $supplier['id'], 'lines' => [['itemId' => $this->f('beans'), 'quantity' => '1', 'unit' => 'kg', 'unitCost' => '140000']]]);
        $this->assertMatchesRegularExpression('#^/admin/purchase-orders/[0-9a-f-]{36}$#', Browser::location($created));
        $show = Browser::page($manager->get(Browser::location($created)));
        $this->assertSame(['submitted', 140_000, false], [$show['props']['order']['status'], $show['props']['order']['total'], $show['props']['order']['canReceive']]);

        $owner = $this->login($this->f('owner'));
        $po = Browser::page($owner->get('/admin/approvals'))['props']['purchaseOrders'][0];
        $flash = $this->flash($owner, $owner->post("/admin/approvals/{$po['id']}/decide", ['approve' => true]));
        $this->assertSame('Purchase order disetujui; barang siap diterima.', $flash['success'] ?? null);

        $path = Browser::location($created);
        $this->assertTrue(Browser::page($manager->get($path))['props']['order']['canReceive']);
        $flash = $this->flash($manager, $manager->post("{$path}/receive", ['lines' => [$this->f('beans') => '1000']]));
        $this->assertSame("Barang {$po['number']} diterima; 1 item masuk ke stok.", $flash['success'] ?? null);
        $this->assertSame(['1500.000', '140.0000'], array_values((array) DB::table('inventory_items')->where('id', $this->f('beans'))->first(['stock_qty', 'purchase_price'])));
        $this->assertSame('received', Browser::page($manager->get($path))['props']['order']['status']);

        $cashier = $this->login($this->f('cashier'));
        $this->assertSame(403, $cashier->get('/admin/purchase-orders')->getStatusCode());
        $this->assertSame(403, $cashier->get('/admin/suppliers')->getStatusCode());
    }

    public function test_resep_lewat_http(): void
    {
        $outletId = $this->f('A')['outletId'];
        $manager = $this->login($this->f('manager'));
        $index = Browser::page($manager->get("/admin/inventory/recipes?outlet={$outletId}"));
        $this->assertSame('admin/inventory/recipes/index', $index['component']);
        $this->assertContains('/admin/inventory/recipes', $index['props']['auth']['navigation']);
        $edit = Browser::page($manager->get("/admin/inventory/recipes/{$this->f('latte')}?outlet={$outletId}"));
        $this->assertSame(['admin/inventory/recipes/edit', true], [$edit['component'], $edit['props']['canEdit']]);

        $saved = $manager->post("/admin/inventory/recipes/{$this->f('latte')}", ['outletId' => $outletId, 'lines' => [['itemId' => $this->f('beans'), 'quantity' => '18', 'unit' => 'g']]]);
        $this->assertSame("/admin/inventory/recipes?outlet={$outletId}", Browser::location($saved));
        $page = Browser::page($manager->get(Browser::location($saved)));
        $this->assertStringStartsWith('Resep tersimpan (1 bahan)', $page['flash']['success'] ?? '');
        $this->assertSame(1, collect($page['props']['products'])->firstWhere('name', 'Latte F2')['lines']);

        $barista = $this->login($this->f('barista'));
        $barista->get("/admin/inventory/recipes/{$this->f('latte')}?outlet={$outletId}");
        $flash = $this->flash($barista, $barista->post("/admin/inventory/recipes/{$this->f('latte')}", ['outletId' => $outletId, 'lines' => []]));
        $this->assertNotEmpty($flash['error'] ?? null, 'barista tidak bisa mengubah resep');
        $this->assertSame(1, DB::table('product_recipe_items')->where('product_id', $this->f('latte'))->count());
    }

    public function test_halaman_pelanggan_dan_batas_akses(): void
    {
        $owner = $this->login($this->f('owner'));
        $page = Browser::page($owner->get('/admin/customers'));
        $this->assertSame('admin/customers/index', $page['component']);
        $this->assertContains('/admin/customers', $page['props']['auth']['navigation']);
        $this->assertSame(['customers' => 0, 'repeat' => 0, 'spending' => 0], $page['props']['summary']);
        $this->assertSame(10_000, $page['props']['pointValue']);
        $this->assertSame(403, $this->login($this->f('barista'))->get('/admin/customers')->getStatusCode());
    }
}
