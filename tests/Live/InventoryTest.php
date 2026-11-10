<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Inventory\EvidenceStorage;
use App\Services\Inventory\InventoryAdmin;
use App\Services\Inventory\StockOpnames;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M5-lite stok (PRD §18–25, §106): item master, ledger, stok masuk/keluar, tautan menu,
 * stock opname dengan persetujuan, izin per peran, dan isolasi tenant.
 */
class InventoryTest extends LiveTestCase
{
    public static array $stored = [];

    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('inva');
        $B = $ctx->createCompany('invb');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'adminA' => $ctx->createUser($A, [['role' => 'admin', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Retail'], $audit)['id'];
        $coldBrew = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Cold Brew Botol', 'categoryId' => $cat, 'basePrice' => '30000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];

        return ['A' => $A, 'B' => $B, ...$users, 'coldBrew' => $coldBrew];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(EvidenceStorage::class, new class implements EvidenceStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void
            {
                InventoryTest::$stored[$path] = $bytes;
            }

            public function download(string $path): ?string
            {
                return InventoryTest::$stored[$path] ?? null;
            }

            public function remove(array $paths): void
            {
                foreach ($paths as $path) {
                    unset(InventoryTest::$stored[$path]);
                }
            }
        });
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function inv(): InventoryAdmin
    {
        return app(InventoryAdmin::class);
    }

    private function outlet(string $company = 'A'): array
    {
        $id = static::$fixture[$company]['outletId'];

        return ['id' => $id, 'name' => 'Outlet', 'timezone' => DB::table('outlets')->where('id', $id)->value('timezone')];
    }

    private function item(string $sku): object
    {
        return DB::table('inventory_items')->where('outlet_id', static::$fixture['A']['outletId'])->where('sku', $sku)->first();
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

    private function newItem(string $actor, array $overrides = []): string
    {
        return $this->inv()->createItem($this->actor($actor), [
            'outletId' => static::$fixture['A']['outletId'], 'sku' => 'bk-arabika', 'name' => 'Biji kopi arabika', 'kind' => 'raw_material',
            'category' => 'Kopi', 'unit' => 'g', 'minimum' => '500', 'purchasePrice' => '', 'expiryDate' => '', 'openingStock' => '1000', ...$overrides,
        ], $this->audit());
    }

    public function test_item_master_saldo_awal_dan_status(): void
    {
        $id = $this->newItem('adminA');
        $item = $this->item('BK-ARABIKA');
        $this->assertSame([$id, '1000.000', '500.000', 'raw_material'], [$item->id, $item->stock_qty, $item->minimum_stock, $item->kind]);
        $opening = DB::table('inventory_transactions')->where('item_id', $id)->first();
        $this->assertSame(['opening', '1000.000', '1000.000', "opening:{$id}"], [$opening->type, $opening->quantity, $opening->balance_after, $opening->idempotency_key]);
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'inventory.item_created', $id));

        $this->expectAccess('conflict', fn () => $this->newItem('adminA', ['name' => 'Duplikat']), '/SKU sudah dipakai/');
        $this->expectAccess('invalid', fn () => $this->newItem('adminA', ['sku' => 'X1', 'unit' => 'ton']), '/satuan/');
        $this->expectAccess('invalid', fn () => $this->newItem('adminA', ['sku' => 'X2', 'unit' => 'pcs', 'openingStock' => '1,5']), '/bilangan bulat/');
        $this->expectAccess('invalid', fn () => $this->newItem('adminA', ['sku' => 'X3', 'expiryDate' => '2020-01-01']), '/sudah lewat/');
        $this->expectAccess('forbidden', fn () => $this->newItem('cashierA', ['sku' => 'X4']));
        $this->expectAccess('forbidden', fn () => $this->newItem('baristaA', ['sku' => 'X5']));

        // Status: Aman → Menipis saat minimum dinaikkan, nonaktif tetap terhitung terpisah.
        $this->inv()->updateItem($this->actor('adminA'), $id, ['sku' => 'BK-ARABIKA', 'name' => 'Biji kopi arabika', 'kind' => 'raw_material', 'unit' => 'g', 'minimum' => '1500', 'isActive' => true], $this->audit());
        $list = $this->inv()->items($this->actor('ownerA'), $this->outlet(), []);
        $this->assertSame('low', collect($list['rows'])->firstWhere('id', $id)['status']);
        $this->assertSame(1, $list['counts']['low']);
        $this->assertSame('1.000 g', collect($list['rows'])->firstWhere('id', $id)['stock']);
        $this->expectAccess('invalid', fn () => $this->inv()->updateItem($this->actor('adminA'), $id, ['sku' => 'BK-ARABIKA', 'name' => 'Biji kopi', 'kind' => 'raw_material', 'unit' => 'kg', 'isActive' => true], $this->audit()), '/Satuan tidak bisa diubah/');
        $this->expectAccess('forbidden', fn () => $this->inv()->items($this->actor('cashierA'), $this->outlet(), []));
        $this->assertSame(['low' => 1], array_intersect_key($this->inv()->dashboard(static::$fixture['A']['id'], static::$fixture['A']['outletId'])['counts'], ['low' => 0]));
    }

    public function test_stok_masuk_keluar_idempoten_dan_izin(): void
    {
        $item = $this->item('BK-ARABIKA');
        $key = (string) Str::uuid();
        $in = ['itemId' => $item->id, 'type' => 'purchase', 'quantity' => '2', 'unit' => 'kg', 'unitCost' => '200000', 'documentRef' => 'NOTA-01', 'idempotencyKey' => $key];
        $first = $this->inv()->stockIn($this->actor('adminA'), $in, $this->audit());
        $this->assertSame(['3.000 g', false], [$first['balance'], $first['replayed']]);
        $this->assertSame(['3000.000', '200.0000'], [$this->item('BK-ARABIKA')->stock_qty, $this->item('BK-ARABIKA')->purchase_price]);
        // Kirim ganda dengan kunci yang sama tidak menambah stok lagi.
        $this->assertTrue($this->inv()->stockIn($this->actor('adminA'), $in, $this->audit())['replayed']);
        $this->assertSame('3000.000', $this->item('BK-ARABIKA')->stock_qty);

        $photo = UploadedFile::fake()->createWithContent('bukti.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 1024));
        $out = $this->inv()->stockOut($this->actor('baristaA'), ['itemId' => $item->id, 'type' => 'waste', 'quantity' => '100', 'unit' => 'g', 'reason' => 'Tumpah', 'idempotencyKey' => (string) Str::uuid()], $photo, $this->audit());
        $this->assertSame('2.900 g', $out['balance']);
        $waste = DB::table('inventory_transactions')->where('item_id', $item->id)->where('type', 'waste')->first();
        $this->assertSame(['-100.000', 'Tumpah'], [$waste->quantity, $waste->reason]);
        $this->assertArrayHasKey($waste->photo_path, self::$stored);
        $this->assertSame("\xFF\xD8\xFF", substr($this->inv()->evidencePhoto($this->actor('ownerA'), $waste->id)['bytes'], 0, 3));
        $this->expectAccess('forbidden', fn () => $this->inv()->evidencePhoto($this->actor('cashierA'), $waste->id));
        $this->expectAccess('not_found', fn () => $this->inv()->evidencePhoto($this->actor('superB'), $waste->id));

        $stored = count(self::$stored);
        $this->expectAccess('conflict', fn () => $this->inv()->stockOut($this->actor('adminA'), ['itemId' => $item->id, 'type' => 'internal_use', 'quantity' => '5', 'unit' => 'kg', 'reason' => 'tes', 'idempotencyKey' => (string) Str::uuid()],
            UploadedFile::fake()->createWithContent('b.jpg', "\xFF\xD8\xFF\xE0xx"), $this->audit()), '/^Stok Biji kopi arabika tidak cukup \(tersisa 2\.900 g\)\.$/');
        $this->assertSame($stored, count(self::$stored), 'foto dari stok keluar yang gagal dihapus lagi');
        $this->expectAccess('invalid', fn () => $this->inv()->stockOut($this->actor('adminA'), ['itemId' => $item->id, 'type' => 'waste', 'quantity' => '1', 'unit' => 'g', 'reason' => 'ab', 'idempotencyKey' => (string) Str::uuid()], null, $this->audit()), '/minimal 3/');
        $this->expectAccess('invalid', fn () => $this->inv()->stockIn($this->actor('adminA'), [...$in, 'idempotencyKey' => 'bukan-uuid'], $this->audit()), '/Form kedaluwarsa/');
        $this->expectAccess('forbidden', fn () => $this->inv()->stockIn($this->actor('baristaA'), [...$in, 'idempotencyKey' => (string) Str::uuid()], $this->audit()));
        $this->expectAccess('forbidden', fn () => $this->inv()->stockOut($this->actor('cashierA'), ['itemId' => $item->id, 'type' => 'waste', 'quantity' => '1', 'unit' => 'g', 'reason' => 'tumpah', 'idempotencyKey' => (string) Str::uuid()], null, $this->audit()));
        $this->expectAccess('not_found', fn () => $this->inv()->stockIn($this->actor('superB'), [...$in, 'idempotencyKey' => (string) Str::uuid()], $this->audit()));

        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'inventory.stock_in', $item->id));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'inventory.stock_out', $item->id));
        $history = $this->inv()->movements($this->actor('ownerA'), $this->outlet(), ['item' => $item->id]);
        $this->assertSame(['waste', 'purchase', 'opening'], array_column($history['rows'], 'type'));
        $this->assertSame(['Waste', '-100 g'], [$history['rows'][0]['typeLabel'], $history['rows'][0]['quantity']]);
        // Ledger append-only: ubah/hapus ditolak database.
        try {
            DB::table('inventory_transactions')->where('id', $waste->id)->update(['reason' => 'diubah']);
            $this->fail('ledger harus menolak update');
        } catch (QueryException $error) {
            $this->assertStringContainsString('append-only', $error->getMessage());
        }
    }

    public function test_tautan_menu_produk_jadi(): void
    {
        $id = $this->newItem('adminA', ['sku' => 'CB-BTL', 'name' => 'Cold Brew Botol 250 ml', 'kind' => 'finished_good', 'unit' => 'botol', 'minimum' => '2', 'openingStock' => '5', 'productIds' => [static::$fixture['coldBrew']]]);
        $this->assertSame($id, DB::table('outlet_products')->where('outlet_id', static::$fixture['A']['outletId'])->where('product_id', static::$fixture['coldBrew'])->value('inventory_item_id'));
        $options = collect($this->inv()->productOptions($this->actor('adminA'), static::$fixture['A']['outletId']))->keyBy('id');
        $this->assertSame('Cold Brew Botol 250 ml', $options[static::$fixture['coldBrew']]['linkedItemName']);
        $this->expectAccess('conflict', fn () => $this->newItem('adminA', ['sku' => 'CB-2', 'name' => 'Cold Brew lain', 'kind' => 'finished_good', 'unit' => 'botol', 'openingStock' => '', 'productIds' => [static::$fixture['coldBrew']]]), '/sudah memakai stok/');
        $this->expectAccess('invalid', fn () => $this->newItem('adminA', ['sku' => 'CB-3', 'name' => 'Asing', 'kind' => 'finished_good', 'unit' => 'botol', 'openingStock' => '', 'productIds' => [(string) Str::uuid()]]), '/tidak ditemukan/');

        // Lepas tautan saat jenis diubah menjadi bukan produk jadi.
        $this->inv()->updateItem($this->actor('adminA'), $id, ['sku' => 'CB-BTL', 'name' => 'Cold Brew Botol 250 ml', 'kind' => 'packaging', 'unit' => 'botol', 'minimum' => '2', 'isActive' => true], $this->audit());
        $this->assertNull(DB::table('outlet_products')->where('outlet_id', static::$fixture['A']['outletId'])->where('product_id', static::$fixture['coldBrew'])->value('inventory_item_id'));
        $this->inv()->updateItem($this->actor('adminA'), $id, ['sku' => 'CB-BTL', 'name' => 'Cold Brew Botol 250 ml', 'kind' => 'finished_good', 'unit' => 'botol', 'minimum' => '2', 'isActive' => true, 'productIds' => [static::$fixture['coldBrew']]], $this->audit());
        $this->assertSame($id, DB::table('outlet_products')->where('outlet_id', static::$fixture['A']['outletId'])->where('product_id', static::$fixture['coldBrew'])->value('inventory_item_id'));
    }

    public function test_stock_opname_dengan_persetujuan(): void
    {
        $opnames = app(StockOpnames::class);
        $outletId = static::$fixture['A']['outletId'];
        $kopi = $this->item('BK-ARABIKA');
        $botol = $this->item('CB-BTL');

        $id = $opnames->create($this->actor('adminA'), $outletId, $this->audit());
        $this->expectAccess('conflict', fn () => $opnames->create($this->actor('managerA'), $outletId, $this->audit()), '/belum selesai/');
        $this->expectAccess('forbidden', fn () => $opnames->create($this->actor('ownerA'), $outletId, $this->audit()));
        $sheet = collect($opnames->get($this->actor('ownerA'), $id)['lines'])->keyBy('itemId');
        $this->assertSame(['2.900 g', '2900'], [$sheet[$kopi->id]['system'], $sheet[$kopi->id]['systemValue']]);

        // Penjualan/keluar setelah snapshot tidak hilang: selisih diterapkan ke saldo saat persetujuan.
        $this->inv()->stockOut($this->actor('adminA'), ['itemId' => $kopi->id, 'type' => 'internal_use', 'quantity' => '100', 'unit' => 'g', 'reason' => 'tes rasa', 'idempotencyKey' => (string) Str::uuid()], null, $this->audit());
        $opnames->saveCounts($this->actor('adminA'), $id, [
            ['itemId' => $kopi->id, 'actual' => '2850', 'reason' => ''],
            ['itemId' => $botol->id, 'actual' => '5', 'reason' => ''],
        ], $this->audit());
        $this->expectAccess('invalid', fn () => $opnames->submit($this->actor('adminA'), $id, $this->audit()), '/Biji kopi arabika: alasan selisih wajib/');
        $this->expectAccess('invalid', fn () => $opnames->saveCounts($this->actor('adminA'), $id, [['itemId' => $botol->id, 'actual' => '1,5', 'reason' => '']], $this->audit()), '/bilangan bulat/');
        $opnames->saveCounts($this->actor('adminA'), $id, [['itemId' => $kopi->id, 'actual' => '2850', 'reason' => 'Tumpah belum dicatat']], $this->audit());
        $result = $opnames->submit($this->actor('adminA'), $id, $this->audit());
        $this->assertSame('submitted', $result['status']);
        $this->expectAccess('conflict', fn () => $opnames->saveCounts($this->actor('adminA'), $id, [['itemId' => $kopi->id, 'actual' => '1', 'reason' => 'x']], $this->audit()), '/sudah diajukan/');

        $pending = $opnames->pending($this->actor('ownerA'), $this->outlet());
        $this->assertCount(1, $pending);
        $this->assertSame([['name' => 'Biji kopi arabika', 'variance' => '-50 g', 'reason' => 'Tumpah belum dicatat']], $pending[0]['lines']);
        $this->expectAccess('forbidden', fn () => $opnames->pending($this->actor('baristaA'), $this->outlet()));
        $this->expectAccess('forbidden', fn () => $opnames->decide($this->actor('adminA'), ['approvalId' => $result['approvalId'], 'approve' => true], $this->audit()));
        $this->expectAccess('not_found', fn () => $opnames->decide($this->actor('superB'), ['approvalId' => $result['approvalId'], 'approve' => true], $this->audit()));

        $this->assertSame(['approved' => true, 'posted' => 1], $opnames->decide($this->actor('managerA'), ['approvalId' => $result['approvalId'], 'approve' => true], $this->audit()));
        $this->assertSame('2750.000', $this->item('BK-ARABIKA')->stock_qty, '2.900 − 100 (setelah snapshot) − 50 (selisih)');
        $this->assertSame('5.000', $this->item('CB-BTL')->stock_qty);
        $this->assertSame('applied', DB::table('stock_opnames')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('inventory_transactions')->where('reference_id', $id)->where('type', 'opname')->count());
        $this->expectAccess('conflict', fn () => $opnames->decide($this->actor('ownerA'), ['approvalId' => $result['approvalId'], 'approve' => false], $this->audit()), '/sudah diputuskan/');

        // Ditolak: stok tidak berubah. Tanpa selisih: langsung selesai tanpa persetujuan.
        $second = $opnames->create($this->actor('managerA'), $outletId, $this->audit());
        $opnames->saveCounts($this->actor('managerA'), $second, [['itemId' => $botol->id, 'actual' => '4', 'reason' => 'Pecah 1']], $this->audit());
        $approvalId = $opnames->submit($this->actor('managerA'), $second, $this->audit())['approvalId'];
        $opnames->decide($this->actor('ownerA'), ['approvalId' => $approvalId, 'approve' => false, 'note' => 'hitung ulang'], $this->audit());
        $this->assertSame(['rejected', '5.000'], [DB::table('stock_opnames')->where('id', $second)->value('status'), $this->item('CB-BTL')->stock_qty]);
        $third = $opnames->create($this->actor('adminA'), $outletId, $this->audit());
        $opnames->saveCounts($this->actor('adminA'), $third, [['itemId' => $botol->id, 'actual' => '5', 'reason' => '']], $this->audit());
        $this->assertSame(['status' => 'applied', 'approvalId' => null], $opnames->submit($this->actor('adminA'), $third, $this->audit()));
        $fourth = $opnames->create($this->actor('adminA'), $outletId, $this->audit());
        $opnames->cancel($this->actor('adminA'), $fourth, $this->audit());
        $this->assertSame('cancelled', DB::table('stock_opnames')->where('id', $fourth)->value('status'));
        $this->assertSame(['cancelled', 'applied', 'rejected', 'applied'], array_column($opnames->list($this->actor('ownerA'), $this->outlet()), 'status'));
    }

    public function test_tenant_lain_tidak_melihat_stok(): void
    {
        $listB = $this->inv()->items($this->actor('superB'), $this->outlet('B'), []);
        $this->assertSame([], $listB['rows']);
        $this->assertSame([], $this->inv()->items($this->actor('superB'), $this->outlet('A'), [])['rows'], 'outlet A ditembak langsung tetap kosong');
        $this->expectAccess('not_found', fn () => $this->inv()->item($this->actor('superB'), $this->item('BK-ARABIKA')->id));
        $this->assertSame([], $this->inv()->movements($this->actor('superB'), $this->outlet('A'), [])['rows']);
        $this->assertSame([], app(StockOpnames::class)->list($this->actor('superB'), $this->outlet('A')));
    }
}
