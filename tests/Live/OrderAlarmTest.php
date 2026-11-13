<?php

namespace Tests\Live;

use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Umpan alarm pesanan baru untuk semua halaman kasir (keputusan owner 12 November 2026: notif
 * pesanan baru di akun kasir harus selalu terdengar, tidak hanya di layar Pesanan masuk).
 * Isinya pesanan pelanggan di antrean + yang menunggu dibayar; pesanan kasir sendiri tidak;
 * barista (absen saja), owner (hanya melihat), dan tenant lain mendapat umpan kosong.
 */
class OrderAlarmTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('alarma');
        $B = $ctx->createCompany('alarmb');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'cashierB' => $ctx->createUser($B, [['role' => 'cashier', 'outlet' => 'outlet']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $category = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], [
            'name' => 'Latte Alarm', 'categoryId' => $category, 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'L1-01']);
        app(CashSessions::class)->open($ctx->load($users['cashierA']['id']), ['outletId' => $A['outletId'], 'openingAmount' => 100_000], $audit);

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $latte];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function input(array $extra = []): array
    {
        return [
            'idempotencyKey' => (string) Str::uuid(), 'customerName' => 'Rina', 'department' => 'Fundraising', 'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['latte'], 'modifierIds' => [], 'quantity' => 1]],
            ...$extra,
        ];
    }

    private function guest(array $extra): array
    {
        return app(OrderEngine::class)->create('qr_table', ['code' => static::$fixture['A']['outletCode']], null, $this->input($extra), $this->audit());
    }

    public function test_umpan_alarm_kasir_berisi_pesanan_pelanggan_baru_dan_menunggu_bayar(): void
    {
        $outlet = ['id' => static::$fixture['A']['outletId']];
        $feed = fn (string $who) => app(OrderEngine::class)->alarmFeed($this->actor($who), $outlet);
        $ids = fn (array $rows) => array_column($rows, 'id');
        $empty = ['queued' => [], 'awaiting' => []];

        $desk = $this->guest(['type' => 'dine_in', 'tableNumber' => 'L1-01', 'floor' => '1', 'area' => 'Depan']);
        $pickup = $this->guest(['type' => 'takeaway']);
        $pos = app(OrderEngine::class)->create('pos', $outlet, $this->actor('cashierA'), $this->input(['type' => 'takeaway']), $this->audit());

        // Antar ke meja + tunai langsung antre; ambil di counter + tunai menunggu bayar di kasir.
        $cashier = $feed('cashierA');
        $row = collect($cashier['queued'])->firstWhere('id', $desk['orderId']);
        $this->assertSame(['qr_table', 'dine_in', 'Rina'], [$row['channel'] ?? null, $row['type'] ?? null, $row['customerName'] ?? null]);
        $this->assertNotNull($row['orderNumber']);
        $this->assertSame([$pickup['orderId']], $ids($cashier['awaiting']));
        $this->assertNotContains($pos['orderId'], [...$ids($cashier['queued']), ...$ids($cashier['awaiting'])], 'pesanan input kasir sendiri tidak dibunyikan');

        // Barista (absen saja), owner (hanya melihat), dan tenant lain: kosong.
        $this->assertSame($empty, $feed('baristaA'));
        $this->assertSame($empty, $feed('ownerA'));
        $this->assertSame($empty, app(OrderEngine::class)->alarmFeed($this->actor('cashierB'), $outlet));

        // Dibayar di kasir: pindah dari menunggu bayar ke antrean dengan id yang sama (tidak berbunyi dua kali).
        app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $pickup['orderId'], 'tenderedAmount' => 30_000], $this->audit());
        $paid = $feed('cashierA');
        $this->assertSame([], $ids($paid['awaiting']));
        $this->assertContains($pickup['orderId'], $ids($paid['queued']));

        // Diterima kasir (mulai dibuat): keluar dari umpan alarm.
        app(OrderWorkflow::class)->updateStatus($this->actor('cashierA'), ['orderId' => $desk['orderId'], 'from' => 'queued', 'to' => 'preparing'], $this->audit());
        $this->assertNotContains($desk['orderId'], $ids($feed('cashierA')['queued']));
    }
}
