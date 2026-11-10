<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Inventory\InventoryAdmin;
use App\Services\Inventory\Recipes;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resep/BOM (PRD §93, Fase 2): bahan per porsi dipotong saat pesanan dibayar, dikembalikan saat
 * pembatalan disetujui, dan TIDAK pernah menolak penjualan bila bahan kurang (stok tidak boleh
 * minus → dipotong sampai habis, kekurangan dicatat). Produk jadi tetap wajib cukup.
 */
class RecipeTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('rcpa');
        $B = $ctx->createCompany('rcpb');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $product = fn (string $name, int $price) => $catalog->createProduct($admin, $A['brandId'], ['name' => $name, 'categoryId' => $coffee, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        $latte = $product('Latte Resep QA', 28000);
        $croissant = $product('Croissant QA', 22000);
        $item = fn (array $raw) => app(InventoryAdmin::class)->createItem($admin, ['outletId' => $A['outletId'], 'minimum' => '0', 'purchasePrice' => '', 'expiryDate' => '', 'category' => 'QA', ...$raw], $audit);

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $latte, 'croissant' => $croissant,
            'beans' => $item(['sku' => 'BIJI-R', 'name' => 'Biji Resep QA', 'kind' => 'raw_material', 'unit' => 'g', 'openingStock' => '1000']),
            'milk' => $item(['sku' => 'SUSU-R', 'name' => 'Susu Resep QA', 'kind' => 'raw_material', 'unit' => 'ml', 'openingStock' => '2000']),
            'pastry' => $item(['sku' => 'CRS-R', 'name' => 'Croissant Stok QA', 'kind' => 'finished_good', 'unit' => 'pcs', 'openingStock' => '5', 'productIds' => [$croissant]]),
        ];
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
        return ['id' => static::$fixture['A']['outletId'], 'name' => 'Outlet Resep QA'];
    }

    private function stock(string $item): string
    {
        return DB::table('inventory_items')->where('id', static::$fixture[$item])->value('stock_qty');
    }

    private function paid(array $lines): string
    {
        $id = app(OrderEngine::class)->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'customerName' => 'Resep QA', 'paymentMethod' => 'cash',
            'lines' => array_map(fn ($l) => ['productId' => static::$fixture[$l[0]], 'modifierIds' => [], 'quantity' => $l[1]], $lines),
        ], $this->audit())['orderId'];
        app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => 500_000], $this->audit());

        return $id;
    }

    private function expectAccess(string $reason, callable $call, ?string $pattern = null): void
    {
        try {
            $call();
            $this->fail("diharapkan AccessException {$reason}");
        } catch (AccessException $error) {
            $this->assertSame($reason, $error->reason, $error->getMessage());
            if ($pattern !== null) {
                $this->assertMatchesRegularExpression($pattern, $error->getMessage());
            }
        }
    }

    public function test_resep_disimpan_dengan_validasi(): void
    {
        $r = app(Recipes::class);
        $base = ['outletId' => static::$fixture['A']['outletId'], 'productId' => static::$fixture['latte']];
        $beans = ['itemId' => static::$fixture['beans'], 'quantity' => '18', 'unit' => 'g'];
        $this->expectAccess('invalid', fn () => $r->save($this->actor('managerA'), [...$base, 'lines' => [$beans, $beans]], $this->audit()), '/dua kali/');
        $this->expectAccess('invalid', fn () => $r->save($this->actor('managerA'), [...$base, 'productId' => static::$fixture['croissant'], 'lines' => [['itemId' => static::$fixture['pastry'], 'quantity' => '1', 'unit' => 'pcs']]], $this->audit()), '/produk jadi/');
        $this->expectAccess('forbidden', fn () => $r->save($this->actor('baristaA'), [...$base, 'lines' => [$beans]], $this->audit()));
        $this->expectAccess('not_found', fn () => $r->save($this->actor('superB'), [...$base, 'lines' => [$beans]], $this->audit()));

        $this->assertSame(2, $r->save($this->actor('managerA'), [...$base, 'lines' => [$beans, ['itemId' => static::$fixture['milk'], 'quantity' => '0,15', 'unit' => 'l']]], $this->audit()));
        $got = $r->get($this->actor('ownerA'), $this->outlet(), static::$fixture['latte']);
        $this->assertSame([['Biji Resep QA', '18', 'g'], ['Susu Resep QA', '150', 'ml']], array_map(fn ($l) => [$l['name'], $l['quantity'], $l['unit']], $got['lines']));
        $this->assertFalse($got['canEdit'], 'Owner hanya melihat resep');
        $this->assertNotContains(static::$fixture['pastry'], array_column($r->get($this->actor('managerA'), $this->outlet(), static::$fixture['croissant'])['items'], 'id'));
        $products = collect($r->products($this->actor('ownerA'), $this->outlet()))->keyBy('name');
        $this->assertSame([2, false], [$products['Latte Resep QA']['lines'], $products['Latte Resep QA']['linkedFinishedGood']]);
        $this->assertTrue($products['Croissant QA']['linkedFinishedGood']);
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'inventory.recipe_saved', static::$fixture['latte']));
    }

    public function test_bayar_memotong_bahan_dan_batal_mengembalikan(): void
    {
        app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => static::$fixture['A']['outletId'], 'openingAmount' => 100_000], $this->audit());
        $order = $this->paid([['latte', 2], ['croissant', 1]]);
        // 2 latte × (18 g + 150 ml); croissant produk jadi 1 pcs.
        $this->assertSame(['964.000', '1700.000', '4.000'], [$this->stock('beans'), $this->stock('milk'), $this->stock('pastry')]);
        $sales = DB::table('inventory_transactions')->where('reference_type', 'order')->where('reference_id', $order)->where('type', 'sale')->pluck('quantity', 'item_id');
        $this->assertSame(['-36.000', '-300.000', '-1.000'], [$sales[static::$fixture['beans']], $sales[static::$fixture['milk']], $sales[static::$fixture['pastry']]]);

        app(OrderWorkflow::class)->cancel($this->actor('baristaA'), ['orderId' => $order, 'reason' => 'salah input resep QA'], $this->audit());
        $approval = DB::table('approvals')->where('reference_id', $order)->where('status', 'pending')->value('id');
        app(OrderWorkflow::class)->decideCancellation($this->actor('ownerA'), ['approvalId' => $approval, 'approve' => true], $this->audit());
        $this->assertSame(['1000.000', '2000.000', '5.000'], [$this->stock('beans'), $this->stock('milk'), $this->stock('pastry')]);
    }

    public function test_bahan_kurang_tidak_menolak_penjualan(): void
    {
        DB::table('inventory_items')->where('id', static::$fixture['milk'])->update(['stock_qty' => '100.000']);
        $order = $this->paid([['latte', 1]]);
        $this->assertSame('paid', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame(['982.000', '0.000'], [$this->stock('beans'), $this->stock('milk')]);
        $log = DB::table('audit_logs')->where('company_id', static::$fixture['A']['id'])->where('action', 'inventory.sale_posted')->where('entity_id', $order)->value('after');
        $items = collect(json_decode($log, true)['items'])->keyBy('item');
        $this->assertSame(['-100.000', '50.000'], [$items['Susu Resep QA']['quantity'], $items['Susu Resep QA']['shortfall']]);
        $this->assertArrayNotHasKey('shortfall', $items['Biji Resep QA']);

        // Susu habis: penjualan tetap jalan, susu tidak dipotong (tanpa movement), biji tetap dipotong.
        $second = $this->paid([['latte', 1]]);
        $this->assertSame(['964.000', '0.000'], [$this->stock('beans'), $this->stock('milk')]);
        $this->assertSame(0, DB::table('inventory_transactions')->where('reference_id', $second)->where('item_id', static::$fixture['milk'])->count());

        // Produk jadi tetap ketat: croissant melebihi stok → pembayaran ditolak seluruhnya.
        DB::table('inventory_items')->where('id', static::$fixture['pastry'])->update(['stock_qty' => '2.000']);
        $id = app(OrderEngine::class)->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'customerName' => 'Resep QA', 'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['croissant'], 'modifierIds' => [], 'quantity' => 2], ['productId' => static::$fixture['latte'], 'modifierIds' => [], 'quantity' => 1]],
        ], $this->audit())['orderId'];
        DB::table('inventory_items')->where('id', static::$fixture['pastry'])->update(['stock_qty' => '1.000']);
        $this->expectAccess('conflict', fn () => app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => 500_000], $this->audit()), '/Croissant Stok QA tidak cukup/');
        $this->assertSame(['964.000', '1.000'], [$this->stock('beans'), $this->stock('pastry')], 'rollback: bahan resep juga tidak terpotong');
    }
}
