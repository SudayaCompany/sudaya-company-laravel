<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Dashboard\DashboardStats;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UX-5: angka dasbor dari transaksi asli dan kotak masuk approval untuk Owner.
 * Angka dibandingkan dengan hitungan manual dari skenario di bawah.
 */
class DashboardTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('dasha');
        $B = $ctx->createCompany('dashb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'adminRoleA' => $ctx->createUser($A, [['role' => 'admin']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $product = fn (string $name, int $price) => $catalog->createProduct($admin, $A['brandId'], [
            'name' => $name, 'categoryId' => $cat, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];
        $latte = $product('Iced Latte', 25000);
        $tea = $product('Iced Tea', 15000);
        $sold = $product('Croissant Habis', 20000);
        DB::table('outlet_products')->updateOrInsert(['outlet_id' => $A['outletId'], 'product_id' => $sold], ['is_available' => false]);

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $latte, 'tea' => $tea];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function outlet(): array
    {
        return ['id' => static::$fixture['A']['outletId'], 'timezone' => DB::table('outlets')->where('id', static::$fixture['A']['outletId'])->value('timezone')];
    }

    private function order(array $lines): string
    {
        return app(OrderEngine::class)->create('pos', ['id' => $this->outlet()['id']], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'paymentMethod' => 'cash',
            'lines' => array_map(fn ($l) => ['productId' => static::$fixture[$l[0]], 'modifierIds' => [], 'quantity' => $l[1]], $lines),
        ], $this->audit())['orderId'];
    }

    private function pay(string $orderId): void
    {
        app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $orderId, 'tenderedAmount' => 200_000], $this->audit());
    }

    private function stats(string $actor): array
    {
        return app(DashboardStats::class)->summary($this->actor($actor), $this->outlet());
    }

    public function test_angka_dasbor_sama_dengan_hitungan_transaksi(): void
    {
        $empty = $this->stats('ownerA');
        $this->assertSame(['revenue' => 0, 'orders' => 0, 'average' => 0, 'items' => 0], $empty['sales']);
        $this->assertSame([], $empty['topProducts']);
        $this->assertSame(['open' => null, 'lastClosed' => null], $empty['cash']);
        $this->assertSame(['count' => 1, 'names' => ['Croissant Habis']], $empty['soldOut']);

        $cash = app(CashSessions::class);
        $session = $cash->open($this->actor('cashierA'), ['outletId' => $this->outlet()['id'], 'openingAmount' => 100_000], $this->audit())['id'];

        // Lunas: 2 latte + 1 tea (65.000), 1 latte (25.000), 3 tea (45.000) = 135.000, 7 item.
        $o1 = $this->order([['latte', 2], ['tea', 1]]);
        $o2 = $this->order([['latte', 1]]);
        $o3 = $this->order([['tea', 3]]);
        foreach ([$o1, $o2, $o3] as $id) {
            $this->pay($id);
        }
        // Belum dibayar: tidak dihitung penjualan.
        $this->order([['latte', 5]]);
        // Lunas lalu dibatalkan (disetujui Manager): keluar dari penjualan.
        $o5 = $this->order([['tea', 2]]);
        $this->pay($o5);
        $flow = app(OrderWorkflow::class);
        $flow->cancel($this->actor('cashierA'), ['orderId' => $o5, 'reason' => 'salah input'], $this->audit());
        $approval = DB::table('approvals')->where('reference_id', $o5)->value('id');
        $flow->decideCancellation($this->actor('managerA'), ['approvalId' => $approval, 'approve' => true], $this->audit());
        // Satu pesanan sampai Selesai.
        foreach (['preparing', 'ready', 'completed'] as $i => $to) {
            $flow->updateStatus($this->actor('managerA'), ['orderId' => $o2, 'from' => ['queued', 'preparing', 'ready'][$i], 'to' => $to], $this->audit());
        }

        $stats = $this->stats('ownerA');
        $this->assertSame(['revenue' => 135_000, 'orders' => 3, 'average' => 45_000, 'items' => 7], $stats['sales']);
        $this->assertSame(1, $stats['pendingPayment']);
        $this->assertSame(2, $stats['inProgress']);
        $this->assertSame(1, $stats['completedToday']);
        $this->assertSame([
            ['name' => 'Iced Tea', 'quantity' => 4, 'revenue' => 60_000],
            ['name' => 'Iced Latte', 'quantity' => 3, 'revenue' => 75_000],
        ], $stats['topProducts']);
        // Saldo sistem: 100.000 + penjualan tunai sesi (135.000 + 30.000 order yang dibatalkan, belum direfund).
        $this->assertSame(265_000, $stats['cash']['open']['expected']);
        $this->assertSame(0, $stats['pendingApprovals']);

        $cash->close($this->actor('managerA'), ['sessionId' => $session, 'actualAmount' => 264_000, 'reason' => 'selisih receh'], $this->audit());
        $closed = $this->stats('managerA');
        $this->assertNull($closed['cash']['open']);
        $this->assertSame(-1_000, $closed['cash']['lastClosed']['variance']);
    }

    public function test_bagian_dasbor_mengikuti_izin_dan_tenant(): void
    {
        // Role admin (pengelola menu) tidak punya cash.view / approval.view.
        $admin = $this->stats('adminRoleA');
        $this->assertNull($admin['cash']);
        $this->assertNull($admin['pendingApprovals']);
        $this->assertNotNull($admin['soldOut']);

        foreach (['cashierA', 'superB'] as $actor) {
            try {
                $this->stats($actor);
                $this->fail("{$actor} seharusnya ditolak");
            } catch (AccessException $error) {
                $this->assertSame('forbidden', $error->reason);
            }
        }
    }

    public function test_kotak_approval_owner_dan_tanda_pengajuan_sendiri(): void
    {
        $cash = app(CashSessions::class);
        $open = DB::table('cash_sessions')->where('outlet_id', $this->outlet()['id'])->where('status', 'open')->first();
        if ($open === null) {
            $cash->open($this->actor('cashierA'), ['outletId' => $this->outlet()['id'], 'openingAmount' => 0], $this->audit());
        }
        $orderId = $this->order([['latte', 1]]);
        $this->pay($orderId);
        $flow = app(OrderWorkflow::class);
        $flow->cancel($this->actor('managerA'), ['orderId' => $orderId, 'reason' => 'customer batal'], $this->audit());

        $engine = app(OrderEngine::class);
        $forOwner = $engine->pendingApprovals($this->actor('ownerA'), $this->outlet());
        $this->assertCount(1, $forOwner);
        $this->assertSame(['customer batal', false, $orderId, 25_000], [$forOwner[0]['reason'], $forOwner[0]['ownRequest'], $forOwner[0]['orderId'], $forOwner[0]['total']]);
        $this->assertTrue($engine->pendingApprovals($this->actor('managerA'), $this->outlet())[0]['ownRequest']);
        $this->assertSame(1, $this->stats('ownerA')['pendingApprovals']);

        try {
            $engine->pendingApprovals($this->actor('cashierA'), $this->outlet());
            $this->fail('kasir tidak punya approval.view');
        } catch (AccessException $error) {
            $this->assertSame('forbidden', $error->reason);
        }
        try {
            $engine->pendingApprovals($this->actor('superB'), $this->outlet());
            $this->fail('company lain');
        } catch (AccessException $error) {
            $this->assertSame('forbidden', $error->reason);
        }

        // Owner memutuskan (bukan pemohon); setelahnya kotak kosong.
        $flow->decideCancellation($this->actor('ownerA'), ['approvalId' => $forOwner[0]['id'], 'approve' => false], $this->audit());
        $this->assertSame([], $engine->pendingApprovals($this->actor('ownerA'), $this->outlet()));
        $this->assertSame('queued', DB::table('orders')->where('id', $orderId)->value('status'));
    }
}
