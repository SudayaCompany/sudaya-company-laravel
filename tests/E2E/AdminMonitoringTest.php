<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Live\LiveContext;

/**
 * UX-5 lewat HTTP sungguhan: dasbor dengan angka asli (deferred), area admin untuk Owner
 * (pesanan, persetujuan, kas hanya-lihat), ekspor menu, dan batas akses per peran/tenant.
 */
class AdminMonitoringTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('e2emon');
        $B = $ctx->createCompany('e2emonb');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Mon'),
            'owner' => $ctx->createUser($A, [['role' => 'owner']], true, 'E2E Owner Mon'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir Mon'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Mon'),
            'ownerB' => $ctx->createUser($B, [['role' => 'owner']], true, 'E2E Owner B'),
        ];
        $admin = $ctx->load($users['admin']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Iced Latte', 'categoryId' => $coffee, 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];

        // Transaksi nyata: sesi kas, dua pesanan lunas, satu diajukan batal oleh barista.
        $cashier = $ctx->load($users['cashier']['id']);
        app(CashSessions::class)->open($cashier, ['outletId' => $A['outletId'], 'openingAmount' => 100_000], $audit);
        $paid = [];
        foreach ([1, 2] as $n) {
            $id = app(OrderEngine::class)->create('pos', ['id' => $A['outletId']], $cashier, [
                'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'customerName' => "Tamu {$n}", 'paymentMethod' => 'cash',
                'lines' => [['productId' => $latte, 'modifierIds' => [], 'quantity' => 1]],
            ], $audit)['orderId'];
            app(OrderWorkflow::class)->confirmCash($cashier, ['orderId' => $id, 'tenderedAmount' => 50_000], $audit);
            $paid[] = $id;
        }
        app(OrderWorkflow::class)->cancel($ctx->load($users['barista']['id']), ['orderId' => $paid[1], 'reason' => 'salah input E2E'], $audit);
        $approval = DB::table('approvals')->where('reference_id', $paid[1])->where('status', 'pending')->value('id');

        return ['A' => $A, 'B' => $B, ...$users, 'kept' => $paid[0], 'cancelled' => $paid[1], 'approval' => $approval];
    }

    private function f(string $key): mixed
    {
        return static::$fixture[$key];
    }

    public function test_dasbor_owner_memuat_angka_asli(): void
    {
        $owner = $this->login($this->f('owner'));
        $response = $owner->get('/admin');
        $this->assertSame(200, $response->getStatusCode());
        $page = Browser::page($response);
        $this->assertSame('admin/dashboard', $page['component']);
        $this->assertSame($this->f('A')['outletId'], $page['props']['outlet']['id']);
        // Angka dimuat sesudah halaman tampil (deferred), tidak ikut di HTML awal.
        $this->assertArrayNotHasKey('summary', $page['props']);
        $this->assertContains('summary', array_merge(...array_values($page['deferredProps'] ?? [])));

        $summary = $owner->partial('/admin', $page, ['summary'])['props']['summary'];
        $this->assertSame([50_000, 2, 25_000, 2], [$summary['sales']['revenue'], $summary['sales']['transactions'], $summary['sales']['average'], $summary['sales']['itemsSold']]);
        $this->assertSame([['name' => 'Iced Latte', 'quantity' => 2, 'amount' => 50_000]], $summary['sales']['topProducts']);
        $this->assertSame(1, $summary['pendingApprovals']);
        $this->assertSame(150_000, $summary['cash']['open']['expected']);
        $this->assertSame('E2E Kasir Mon', $summary['cash']['open']['openedByName']);

        // Outlet tenant lain lewat ?outlet= diabaikan: tetap outlet sendiri.
        $page = Browser::page($owner->get('/admin?outlet='.$this->f('B')['outletId']));
        $this->assertSame($this->f('A')['outletId'], $page['props']['outlet']['id']);
        $this->assertSame([$this->f('A')['outletId']], array_column($page['props']['outlets'], 'id'));
    }

    public function test_owner_memantau_pesanan_kas_dan_memutuskan_persetujuan(): void
    {
        $owner = $this->login($this->f('owner'));
        $nav = Browser::page($owner->get('/admin'))['props']['auth']['navigation'];
        foreach (['/admin/orders', '/admin/approvals', '/admin/cash'] as $path) {
            $this->assertContains($path, $nav);
        }
        $this->assertNotContains('/pos', $nav);
        $this->assertSame(403, $owner->get('/pos/orders')->getStatusCode(), 'Owner tidak memakai area kasir');

        $orders = Browser::page($owner->get('/admin/orders?view=paid'));
        $this->assertSame(['pos/orders/index', 'admin'], [$orders['component'], $orders['props']['area']]);
        $this->assertEqualsCanonicalizing([$this->f('kept'), $this->f('cancelled')], array_column($orders['props']['list']['rows'], 'id'));

        $detail = Browser::page($owner->get('/admin/orders/'.$this->f('cancelled')));
        $this->assertSame(['pos/orders/show', 'admin'], [$detail['component'], $detail['props']['area']]);
        // Area admin: hanya memantau + memutuskan; aksi kasir tidak ditawarkan.
        $this->assertSame(['canConfirmCash' => false, 'canCancel' => false, 'canDecide' => true, 'canRefund' => false], $detail['props']['flags']);
        $this->assertSame('pos/orders/receipt', Browser::page($owner->get('/admin/orders/'.$this->f('kept').'/receipt'))['component']);

        $cash = Browser::page($owner->get('/admin/cash'));
        $this->assertSame(['pos/cash', 'admin', false, false], [$cash['component'], $cash['props']['area'], $cash['props']['canOpen'], $cash['props']['canClose']]);
        $this->assertSame(150_000, $cash['props']['current']['totals']['expected']);

        $inbox = Browser::page($owner->get('/admin/approvals'));
        $this->assertSame('admin/approvals', $inbox['component']);
        $this->assertCount(1, $inbox['props']['approvals']);
        $this->assertSame($this->f('approval'), $inbox['props']['approvals'][0]['id']);
        $this->assertTrue($inbox['props']['approvals'][0]['canDecide']);

        $response = $owner->post('/admin/approvals/'.$this->f('approval').'/decide', ['approve' => true]);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/approvals', Browser::location($response));
        $after = Browser::page($owner->get('/admin/approvals'));
        $this->assertSame('Pembatalan disetujui.', $after['flash']['success'] ?? null);
        $this->assertSame([], $after['props']['approvals']);
        $this->assertSame('cancelled', DB::table('orders')->where('id', $this->f('cancelled'))->value('status'));
        $this->assertSame('approved', DB::table('approvals')->where('id', $this->f('approval'))->value('status'));
        $this->assertSame(1, $this->ctx()->countAudit($this->f('A')['id'], 'approval.approved'));

        // Keputusan kedua ditolak (sudah diputuskan).
        $owner->get('/admin/approvals');
        $owner->post('/admin/approvals/'.$this->f('approval').'/decide', ['approve' => false]);
        $this->assertSame('Pengajuan ini sudah diputuskan.', Browser::page($owner->get('/admin/approvals'))['flash']['error'] ?? null);
    }

    public function test_ekspor_menu_lewat_http(): void
    {
        $owner = $this->login($this->f('owner'));
        $response = $owner->get('/admin/products/export');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaderLine('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="menu-[a-z0-9-]+-\d{4}-\d{2}-\d{2}\.xlsx"$/', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('PK', substr((string) $response->getBody(), 0, 2), 'file zip (xlsx)');

        $this->assertSame(403, $this->login($this->f('barista'))->get('/admin/products/export')->getStatusCode());
        $guest = $this->browser()->get('/admin/products/export');
        $this->assertSame(302, $guest->getStatusCode());
        $this->assertStringStartsWith('/login', Browser::location($guest));
    }

    public function test_batas_akses_peran_dan_tenant(): void
    {
        $barista = $this->login($this->f('barista'));
        foreach (['/admin', '/admin/approvals', '/admin/cash'] as $path) {
            $this->assertSame(403, $barista->get($path)->getStatusCode(), $path);
        }
        $barista->get('/kds');
        $this->assertSame(403, $barista->post('/admin/approvals/'.Str::uuid().'/decide', ['approve' => true])->getStatusCode());

        foreach (['/admin/approvals', '/admin/orders', '/admin/cash'] as $path) {
            $guest = $this->browser()->get($path);
            $this->assertSame(302, $guest->getStatusCode(), $path);
            $this->assertStringStartsWith('/login', Browser::location($guest));
        }

        // Owner tenant lain: detail pesanan A tidak ditemukan; kotak persetujuan & kas miliknya sendiri.
        $ownerB = $this->login($this->f('ownerB'));
        $this->assertSame(404, $ownerB->get('/admin/orders/'.$this->f('kept'))->getStatusCode());
        $inbox = Browser::page($ownerB->get('/admin/approvals?outlet='.$this->f('A')['outletId']));
        $this->assertSame($this->f('B')['outletId'], $inbox['props']['outlet']['id']);
        $this->assertSame([], $inbox['props']['approvals']);
        $this->assertNull(Browser::page($ownerB->get('/admin/cash'))['props']['current']);
    }
}
