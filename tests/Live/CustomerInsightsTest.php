<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Customers\CustomerInsights;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data pelanggan & poin (PRD §51–55, Fase 2, hanya-baca): dari pesanan lunas bernomor HP,
 * pembatalan tidak dihitung, nomor disamarkan, izin customer.view, tanpa lintas tenant.
 */
class CustomerInsightsTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('cusa');
        $B = $ctx->createCompany('cusb');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $product = fn (string $name, int $price) => $catalog->createProduct($admin, $A['brandId'], ['name' => $name, 'categoryId' => $cat, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $product('Latte CRM', 30000), 'tea' => $product('Teh CRM', 15000)];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    /** Pesanan POS lunas lalu dicap nomor HP & nama (data test, setara checkout QR yang mengisi HP). */
    private function paid(array $lines, ?string $phone, ?string $name): string
    {
        $id = app(OrderEngine::class)->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'customerName' => $name ?? 'Tamu', 'department' => 'Fundraising', 'paymentMethod' => 'cash',
            'lines' => array_map(fn ($l) => ['productId' => static::$fixture[$l[0]], 'modifierIds' => [], 'quantity' => $l[1]], $lines),
        ], $this->audit())['orderId'];
        app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => 500_000], $this->audit());
        DB::table('orders')->where('id', $id)->update(['customer_phone' => $phone, 'customer_name' => $name]);

        return $id;
    }

    public function test_data_pelanggan_poin_tier_dan_izin(): void
    {
        app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => static::$fixture['A']['outletId'], 'openingAmount' => 100_000], $this->audit());
        $this->paid([['latte', 4]], '081234567890', 'Rina');        // 120.000
        $this->paid([['latte', 6], ['tea', 2]], '081234567890', 'Rina S.'); // 210.000 → total 330.000 (Silver, 33 poin)
        $this->paid([['tea', 1]], '085700001111', 'Budi');          // 15.000
        $this->paid([['tea', 1]], null, 'Tanpa HP');                // tidak tercatat
        $cancelled = $this->paid([['latte', 10]], '085700001111', 'Budi');
        app(OrderWorkflow::class)->cancel($this->actor('cashierA'), ['orderId' => $cancelled, 'reason' => 'salah input CRM QA'], $this->audit());
        $approval = DB::table('approvals')->where('reference_id', $cancelled)->where('status', 'pending')->value('id');
        app(OrderWorkflow::class)->decideCancellation($this->actor('ownerA'), ['approvalId' => $approval, 'approve' => true], $this->audit());

        $outlet = ['id' => static::$fixture['A']['outletId']];
        $data = app(CustomerInsights::class)->list($this->actor('ownerA'), $outlet, null);
        $this->assertSame(['customers' => 2, 'repeat' => 1, 'spending' => 345_000], $data['summary']);
        $rina = $data['customers'][0];
        $this->assertSame(['Rina S.', '0812****7890', '081234567890', 2, 330_000, 165_000, 33, 'Silver'], [$rina['name'], $rina['phoneMasked'], $rina['phoneFull'], $rina['transactions'], $rina['spending'], $rina['average'], $rina['points'], $rina['tier']]);
        $this->assertSame([['name' => 'Latte CRM', 'quantity' => 10], ['name' => 'Teh CRM', 'quantity' => 2]], $rina['favorites']);
        $budi = $data['customers'][1];
        $this->assertSame([1, 15_000, 1, 'Member'], [$budi['transactions'], $budi['spending'], $budi['points'], $budi['tier']], 'pesanan batal tidak dihitung');

        $this->assertSame(['Budi'], array_column(app(CustomerInsights::class)->list($this->actor('cashierA'), $outlet, 'budi')['customers'], 'name'));
        $this->assertSame(['Rina S.'], array_column(app(CustomerInsights::class)->list($this->actor('ownerA'), $outlet, '4567')['customers'], 'name'));
        try {
            app(CustomerInsights::class)->list($this->actor('baristaA'), $outlet, null);
            $this->fail('barista tidak punya customer.view');
        } catch (AccessException $error) {
            $this->assertSame('forbidden', $error->reason);
        }
        $this->assertSame(0, app(CustomerInsights::class)->list($this->actor('superB'), $outlet, null)['summary']['customers'], 'tenant lain tidak melihat pelanggan A');
        $this->assertSame('Gold', CustomerInsights::tier(1_000_000));
    }
}
