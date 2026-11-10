<?php

namespace Tests\E2E;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Tests\Live\LiveContext;

/**
 * M5-lite lewat HTTP sungguhan: item stok, stok masuk, stok keluar dengan foto (bucket
 * privat asli), riwayat + foto berizin, stock opname → kotak Persetujuan, batas akses.
 */
class InventoryFlowTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $A = $ctx->createCompany('e2estk');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Stok'),
            'owner' => $ctx->createUser($A, [['role' => 'owner']], true, 'E2E Owner Stok'),
            'manager' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']], true, 'E2E Manager Stok'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Stok'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir Stok'),
        ];

        return ['A' => $A, ...$users];
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

    private function item(): object
    {
        return DB::table('inventory_items')->where('outlet_id', $this->f('A')['outletId'])->where('sku', 'SUSU-UHT')->first();
    }

    public function test_item_stok_masuk_dan_keluar_lewat_http(): void
    {
        $outletId = $this->f('A')['outletId'];
        $admin = $this->login($this->f('admin'));
        $page = Browser::page($admin->get('/admin/inventory'));
        $this->assertSame('admin/inventory/index', $page['component']);
        $this->assertSame(['ok' => 0, 'low' => 0, 'out' => 0, 'inactive' => 0], $page['props']['counts']);
        foreach (['/admin/inventory', '/admin/inventory/stock-in', '/admin/inventory/stock-out', '/admin/inventory/opname', '/admin/inventory/movements'] as $path) {
            $this->assertContains($path, $page['props']['auth']['navigation']);
        }

        $this->assertSame('admin/inventory/item-form', Browser::page($admin->get("/admin/inventory/items/create?outlet={$outletId}"))['component']);
        $created = $admin->post('/admin/inventory/items', ['outletId' => $outletId, 'sku' => 'susu-uht', 'name' => 'Susu UHT', 'kind' => 'raw_material', 'unit' => 'ml', 'minimum' => '1000', 'openingStock' => '5000', 'purchasePrice' => '', 'expiryDate' => '', 'category' => 'Susu']);
        $this->assertSame("/admin/inventory?outlet={$outletId}", Browser::location($created));
        $this->assertSame('Item stok ditambahkan.', Browser::page($admin->get(Browser::location($created)))['flash']['success'] ?? null);
        $item = $this->item();
        $this->assertSame('5000.000', $item->stock_qty);

        $admin->get('/admin/inventory/stock-in');
        $flash = $this->flash($admin, $admin->post('/admin/inventory/stock-in', ['itemId' => $item->id, 'type' => 'purchase', 'quantity' => '2', 'unit' => 'l', 'unitCost' => '18000', 'idempotencyKey' => (string) Str::uuid()]));
        $this->assertSame('Stok masuk tercatat. Stok sekarang 7.000 ml.', $flash['success'] ?? null);
        $this->assertSame('18.0000', $this->item()->purchase_price, 'Rp18.000/l = Rp18/ml');

        // Barista mencatat waste dengan foto: tersimpan di bucket privat asli.
        $barista = $this->login($this->f('barista'));
        $out = Browser::page($barista->get('/admin/inventory/stock-out'));
        $this->assertSame('admin/inventory/stock-out', $out['component']);
        $photo = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        $flash = $this->flash($barista, $barista->multipart('/admin/inventory/stock-out', ['itemId' => $item->id, 'type' => 'waste', 'quantity' => '250', 'unit' => 'ml', 'reason' => 'Tumpah saat steaming', 'idempotencyKey' => (string) Str::uuid()], ['photo' => ['name' => 'bukti.jpg', 'bytes' => $photo, 'type' => 'image/jpeg']]));
        $this->assertSame('Stok keluar tercatat. Stok sekarang 6.750 ml.', $flash['success'] ?? null);
        $this->assertSame(403, $barista->get('/admin/inventory/stock-in')->getStatusCode(), 'barista tidak menerima barang');

        $owner = $this->login($this->f('owner'));
        $history = Browser::page($owner->get("/admin/inventory/movements?item={$item->id}"));
        $this->assertSame(['waste', 'purchase', 'opening'], array_column($history['props']['rows'], 'type'));
        $waste = $history['props']['rows'][0];
        $this->assertTrue($waste['hasPhoto']);
        $image = $owner->get("/admin/inventory/movements/{$waste['id']}/photo");
        $this->assertSame([200, 'image/jpeg'], [$image->getStatusCode(), $image->getHeaderLine('Content-Type')]);
        $this->assertStringContainsString('no-store', $image->getHeaderLine('Cache-Control'));
        $this->assertSame("\xFF\xD8\xFF", substr((string) $image->getBody(), 0, 3));
        $this->assertSame(403, $owner->get('/admin/inventory/stock-in')->getStatusCode(), 'owner hanya memantau stok');
    }

    public function test_opname_lewat_http_dan_kotak_persetujuan(): void
    {
        $outletId = $this->f('A')['outletId'];
        $item = $this->item();
        $manager = $this->login($this->f('manager'));
        $manager->get('/admin/inventory/opname');
        $created = $manager->post('/admin/inventory/opname', ['outletId' => $outletId]);
        $this->assertMatchesRegularExpression('#^/admin/inventory/opname/[0-9a-f-]{36}$#', Browser::location($created));
        $path = Browser::location($created);
        $sheet = Browser::page($manager->get($path));
        $this->assertSame('admin/inventory/opname/show', $sheet['component']);
        $this->assertSame('6750', $sheet['props']['opname']['lines'][0]['systemValue']);

        $flash = $this->flash($manager, $manager->post("{$path}/counts", ['lines' => [['itemId' => $item->id, 'actual' => '6500', 'reason' => 'Kemasan bocor']]]));
        $this->assertSame('1 hitungan tersimpan.', $flash['success'] ?? null);
        $flash = $this->flash($manager, $manager->post("{$path}/submit"));
        $this->assertStringStartsWith('Opname diajukan.', $flash['success'] ?? '');

        $owner = $this->login($this->f('owner'));
        $inbox = Browser::page($owner->get('/admin/approvals'));
        $this->assertCount(1, $inbox['props']['opnames']);
        $this->assertSame([['name' => 'Susu UHT', 'variance' => '-250 ml', 'reason' => 'Kemasan bocor']], $inbox['props']['opnames'][0]['lines']);
        $flash = $this->flash($owner, $owner->post("/admin/approvals/{$inbox['props']['opnames'][0]['id']}/decide", ['approve' => true]));
        $this->assertSame('Stock opname disetujui, stok diperbarui.', $flash['success'] ?? null);
        $this->assertSame('6500.000', $this->item()->stock_qty);

        // Dasbor Owner menampilkan ringkasan stok (deferred).
        $page = Browser::page($owner->get('/admin'));
        $summary = $owner->partial('/admin', $page, ['summary'])['props']['summary'];
        $this->assertSame(['ok' => 1, 'low' => 0, 'out' => 0, 'inactive' => 0], $summary['stock']['counts']);
        $this->assertSame(0, $summary['pendingOpnames']);
    }

    public function test_batas_akses_stok(): void
    {
        $cashier = $this->login($this->f('cashier'));
        foreach (['/admin/inventory', '/admin/inventory/stock-in', '/admin/inventory/stock-out', '/admin/inventory/opname', '/admin/inventory/movements'] as $path) {
            $this->assertSame(403, $cashier->get($path)->getStatusCode(), $path);
        }
        // Barista boleh melihat stok, tetapi membuat item (inventory.adjust) ditolak service.
        $barista = $this->login($this->f('barista'));
        $barista->get('/admin/inventory');
        $flash = $this->flash($barista, $barista->post('/admin/inventory/items', ['outletId' => $this->f('A')['outletId'], 'sku' => 'X-BARISTA', 'name' => 'Item Barista', 'kind' => 'raw_material', 'unit' => 'g']));
        $this->assertNotEmpty($flash['error'] ?? null);
        $this->assertSame(0, DB::table('inventory_items')->where('sku', 'X-BARISTA')->count());
        foreach (['/admin/inventory', '/admin/inventory/opname'] as $path) {
            $guest = $this->browser()->get($path);
            $this->assertSame(302, $guest->getStatusCode(), $path);
            $this->assertStringStartsWith('/login', Browser::location($guest));
        }
    }
}
