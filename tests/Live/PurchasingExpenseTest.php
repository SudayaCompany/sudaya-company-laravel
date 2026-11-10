<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Finance\Expenses;
use App\Services\Inventory\EvidenceStorage;
use App\Services\Inventory\InventoryAdmin;
use App\Services\Purchasing\Purchasing;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 PRD: supplier & purchase order (§26–27: ajukan → setujui → terima → stok bertambah)
 * dan pengeluaran (§59: catat + bukti → persetujuan). Izin memakai peran yang sudah ada;
 * pemohon tidak memutuskan sendiri; tenant lain tidak pernah melihat data.
 */
class PurchasingExpenseTest extends LiveTestCase
{
    public static array $stored = [];

    protected function fixtures(LiveContext $ctx): array
    {
        $A = $ctx->createCompany('pura');
        $B = $ctx->createCompany('purb');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'adminA' => $ctx->createUser($A, [['role' => 'admin', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $item = app(InventoryAdmin::class)->createItem($ctx->load($users['superA']['id']), [
            'outletId' => $A['outletId'], 'sku' => 'BIJI-QA', 'name' => 'Biji Arabika QA', 'kind' => 'raw_material', 'unit' => 'g',
            'minimum' => '500', 'openingStock' => '1000', 'purchasePrice' => '', 'expiryDate' => '', 'category' => 'Kopi',
        ], new AuditContext('127.0.0.1', 'phpunit'));

        return ['A' => $A, 'B' => $B, ...$users, 'item' => $item];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(EvidenceStorage::class, new class implements EvidenceStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void
            {
                PurchasingExpenseTest::$stored[$path] = $bytes;
            }

            public function download(string $path): ?string
            {
                return PurchasingExpenseTest::$stored[$path] ?? null;
            }

            public function remove(array $paths): void
            {
                foreach ($paths as $path) {
                    unset(PurchasingExpenseTest::$stored[$path]);
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

    private function outlet(string $company = 'A'): array
    {
        $id = static::$fixture[$company]['outletId'];
        $row = DB::table('outlets')->where('id', $id)->first(['name', 'timezone']);

        return ['id' => $id, 'name' => $row->name, 'timezone' => $row->timezone];
    }

    private function today(): string
    {
        return CarbonImmutable::now($this->outlet()['timezone'])->toDateString();
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

    private function purchasing(): Purchasing
    {
        return app(Purchasing::class);
    }

    private function expenses(): Expenses
    {
        return app(Expenses::class);
    }

    public function test_supplier_master_dan_izin(): void
    {
        $p = $this->purchasing();
        $this->expectAccess('invalid', fn () => $p->saveSupplier($this->actor('managerA'), null, ['name' => 'X'], $this->audit()), '/Nama supplier 2–120/');
        $this->expectAccess('invalid', fn () => $p->saveSupplier($this->actor('managerA'), null, ['name' => 'CV Kopi QA', 'email' => 'bukan-email'], $this->audit()), '/email/');
        $this->expectAccess('forbidden', fn () => $p->saveSupplier($this->actor('baristaA'), null, ['name' => 'CV Kopi QA'], $this->audit()));
        $this->expectAccess('forbidden', fn () => $p->saveSupplier($this->actor('ownerA'), null, ['name' => 'CV Kopi QA'], $this->audit()), null);

        $id = $p->saveSupplier($this->actor('managerA'), null, ['name' => 'CV Kopi QA', 'contactName' => 'Pak Budi', 'phone' => '0812-3456-789', 'products' => 'Biji arabika', 'paymentTerm' => 'Tempo 14 hari'], $this->audit());
        $this->expectAccess('conflict', fn () => $p->saveSupplier($this->actor('adminA'), null, ['name' => 'cv kopi qa'], $this->audit()), '/sudah dipakai/');
        $p->saveSupplier($this->actor('adminA'), $id, ['name' => 'CV Kopi QA', 'phone' => '0812-3456-789', 'paymentTerm' => 'Tunai', 'isActive' => true], $this->audit());
        $list = $p->suppliers($this->actor('ownerA'));
        $this->assertSame([['CV Kopi QA', 'Tunai', 0]], array_map(fn ($s) => [$s['name'], $s['paymentTerm'], $s['orders']], $list));
        $this->assertSame([], $p->suppliers($this->actor('superB')));
        $this->expectAccess('forbidden', fn () => $p->suppliers($this->actor('cashierA')));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'supplier.created', $id));
    }

    public function test_purchase_order_ajukan_setujui_terima(): void
    {
        $p = $this->purchasing();
        $supplier = DB::table('suppliers')->where('company_id', static::$fixture['A']['id'])->value('id');
        $base = ['outletId' => static::$fixture['A']['outletId'], 'supplierId' => $supplier, 'expectedDate' => $this->today()];
        $this->expectAccess('invalid', fn () => $p->create($this->actor('managerA'), [...$base, 'lines' => []], $this->audit()), '/1–50 baris/');
        $this->expectAccess('forbidden', fn () => $p->create($this->actor('baristaA'), [...$base, 'lines' => [['itemId' => static::$fixture['item'], 'quantity' => '1', 'unit' => 'kg', 'unitCost' => '150000']]], $this->audit()));

        // 2 kg @ Rp150.000/kg → 2.000 g @ Rp150/g, total Rp300.000.
        $po = $p->create($this->actor('managerA'), [...$base, 'lines' => [['itemId' => static::$fixture['item'], 'quantity' => '2', 'unit' => 'kg', 'unitCost' => '150000']]], $this->audit());
        $this->assertMatchesRegularExpression('/^PO-\d{6}-01$/', $po['number']);
        $row = DB::table('purchase_orders')->where('id', $po['id'])->first();
        $this->assertSame(['submitted', 300_000], [$row->status, (int) $row->total]);
        $line = DB::table('purchase_order_items')->where('purchase_order_id', $po['id'])->first();
        $this->assertSame(['2000.000', '150.0000'], [$line->quantity, $line->unit_cost]);
        $this->expectAccess('conflict', fn () => $p->receive($this->actor('managerA'), $po['id'], [], $this->audit()), '/harus disetujui/');

        $inbox = $p->pending($this->actor('ownerA'), $this->outlet());
        $this->assertSame([[$po['number'], 'CV Kopi QA', 300_000, ['Biji Arabika QA 2.000 g'], true]], array_map(fn ($i) => [$i['number'], $i['supplier'], $i['total'], $i['lines'], $i['canDecide']], $inbox));
        $this->expectAccess('forbidden', fn () => $p->decide($this->actor('managerA'), ['approvalId' => $po['approvalId'], 'approve' => true], $this->audit()), '/pengajuan Anda sendiri/');
        $this->assertSame(['approved' => true], $p->decide($this->actor('ownerA'), ['approvalId' => $po['approvalId'], 'approve' => true], $this->audit()));
        $this->assertSame('approved', DB::table('purchase_orders')->where('id', $po['id'])->value('status'));

        // Terima 1.800 g (sebagian): stok 1.000 → 2.800 g, harga beli Rp150/g, ledger "purchase" idempotent.
        $this->assertSame(['number' => $po['number'], 'lines' => 1], $p->receive($this->actor('adminA'), $po['id'], ['lines' => [static::$fixture['item'] => '1800']], $this->audit()));
        $item = DB::table('inventory_items')->where('id', static::$fixture['item'])->first();
        $this->assertSame(['2800.000', '150.0000'], [$item->stock_qty, $item->purchase_price]);
        $movement = DB::table('inventory_transactions')->where('reference_type', 'purchase_order')->where('reference_id', $po['id'])->first();
        $this->assertSame(['purchase', '1800.000', $po['number']], [$movement->type, $movement->quantity, $movement->document_ref]);
        $this->assertSame(['received', '1800.000'], [DB::table('purchase_orders')->where('id', $po['id'])->value('status'), DB::table('purchase_order_items')->where('purchase_order_id', $po['id'])->value('received_quantity')]);
        $this->expectAccess('conflict', fn () => $p->receive($this->actor('adminA'), $po['id'], [], $this->audit()), '/sudah diterima/');
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'purchase_order.received', $po['id']));

        $detail = $p->get($this->actor('ownerA'), $po['id']);
        $this->assertSame(['received', false, false, '1.800 g'], [$detail['status'], $detail['canReceive'], $detail['canCancel'], $detail['lines'][0]['received']]);
        $this->assertSame(300_000, $p->suppliers($this->actor('ownerA'))[0]['receivedTotal']);
    }

    public function test_purchase_order_ditolak_dibatalkan_dan_isolasi(): void
    {
        $p = $this->purchasing();
        $supplier = DB::table('suppliers')->where('company_id', static::$fixture['A']['id'])->value('id');
        $lines = [['itemId' => static::$fixture['item'], 'quantity' => '500', 'unit' => 'g', 'unitCost' => '160']];
        $rejected = $p->create($this->actor('adminA'), ['outletId' => static::$fixture['A']['outletId'], 'supplierId' => $supplier, 'lines' => $lines], $this->audit());
        $this->assertMatchesRegularExpression('/-02$/', $rejected['number'], 'nomor PO berurutan per hari');
        $p->decide($this->actor('managerA'), ['approvalId' => $rejected['approvalId'], 'approve' => false, 'note' => 'Stok cukup'], $this->audit());
        $this->assertSame('rejected', DB::table('purchase_orders')->where('id', $rejected['id'])->value('status'));
        $this->expectAccess('conflict', fn () => $p->receive($this->actor('adminA'), $rejected['id'], [], $this->audit()));

        $cancel = $p->create($this->actor('adminA'), ['outletId' => static::$fixture['A']['outletId'], 'supplierId' => $supplier, 'lines' => $lines], $this->audit());
        $p->cancel($this->actor('managerA'), $cancel['id'], $this->audit());
        $this->assertSame(['cancelled', 'cancelled'], [DB::table('purchase_orders')->where('id', $cancel['id'])->value('status'), DB::table('approvals')->where('id', $cancel['approvalId'])->value('status')]);
        $this->expectAccess('conflict', fn () => $p->cancel($this->actor('managerA'), $cancel['id'], $this->audit()));
        $this->assertSame([], $p->pending($this->actor('ownerA'), $this->outlet()));

        $this->expectAccess('not_found', fn () => $p->get($this->actor('superB'), $cancel['id']));
        $this->expectAccess('not_found', fn () => $p->decide($this->actor('superB'), ['approvalId' => $rejected['approvalId'], 'approve' => true], $this->audit()));
        $this->expectAccess('invalid', fn () => $p->create($this->actor('superB'), ['outletId' => static::$fixture['B']['outletId'], 'supplierId' => $supplier, 'lines' => $lines], $this->audit()), '/supplier yang aktif/');
        $this->assertSame(3, count($p->orders($this->actor('ownerA'), $this->outlet(), null)));
        $this->assertSame(1, count($p->orders($this->actor('ownerA'), $this->outlet(), 'received')));
    }

    public function test_pengeluaran_catat_setujui_dan_rekap(): void
    {
        $x = $this->expenses();
        $base = ['outletId' => static::$fixture['A']['outletId'], 'expenseDate' => $this->today(), 'category' => 'parkir', 'amount' => '5000', 'description' => 'Parkir belanja QA'];
        $this->expectAccess('invalid', fn () => $x->create($this->actor('cashierA'), [...$base, 'category' => 'jajan'], null, $this->audit()), '/kategori/');
        $this->expectAccess('invalid', fn () => $x->create($this->actor('cashierA'), [...$base, 'amount' => '0'], null, $this->audit()));
        $this->expectAccess('invalid', fn () => $x->create($this->actor('cashierA'), [...$base, 'expenseDate' => CarbonImmutable::parse($this->today())->addDay()->toDateString()], null, $this->audit()), '/masa depan/');
        $this->expectAccess('invalid', fn () => $x->create($this->actor('cashierA'), [...$base, 'description' => 'ab'], null, $this->audit()), '/Keterangan/');
        // Owner punya cash.view (kas hanya-lihat) sehingga boleh mencatat; tenant lain tidak menemukan outlet A.
        $this->expectAccess('not_found', fn () => $x->create($this->actor('superB'), $base, null, $this->audit()));

        $photo = UploadedFile::fake()->createWithContent('nota.jpg', "\xFF\xD8\xFF\xE0".str_repeat('n', 2048));
        $byCashier = $x->create($this->actor('cashierA'), $base, $photo, $this->audit());
        $byBarista = $x->create($this->actor('baristaA'), [...$base, 'category' => 'pembelian_kecil', 'amount' => '27500', 'description' => 'Es batu 2 kantong'], null, $this->audit());
        $byManager = $x->create($this->actor('managerA'), [...$base, 'category' => 'maintenance', 'amount' => '150000', 'description' => 'Servis grinder'], null, $this->audit());
        $evidencePath = DB::table('expenses')->where('id', $byCashier['expenseId'])->value('evidence_path');
        $this->assertArrayHasKey($evidencePath, self::$stored);

        // Pengaju hanya melihat miliknya; peninjau melihat semua.
        $this->assertSame([$byCashier['expenseId']], array_column($x->list($this->actor('cashierA'), $this->outlet(), null)['rows'], 'id'));
        $owner = $x->list($this->actor('ownerA'), $this->outlet(), null);
        $this->assertSame([3, 182_500, 0], [$owner['totals']['count'], $owner['totals']['pending'], $owner['totals']['approved']]);

        $inbox = collect($x->pending($this->actor('managerA'), $this->outlet()))->keyBy('expenseId');
        $this->assertFalse($inbox[$byManager['expenseId']]['canDecide'], 'manager tidak memutuskan pengeluarannya sendiri');
        $this->assertTrue($inbox[$byCashier['expenseId']]['canDecide']);
        $this->expectAccess('forbidden', fn () => $x->decide($this->actor('managerA'), ['approvalId' => $byManager['approvalId'], 'approve' => true], $this->audit()));
        $this->expectAccess('forbidden', fn () => $x->pending($this->actor('cashierA'), $this->outlet()));
        $x->decide($this->actor('managerA'), ['approvalId' => $byCashier['approvalId'], 'approve' => true], $this->audit());
        $x->decide($this->actor('ownerA'), ['approvalId' => $byManager['approvalId'], 'approve' => true], $this->audit());
        $x->decide($this->actor('ownerA'), ['approvalId' => $byBarista['approvalId'], 'approve' => false, 'note' => 'Pakai kas kecil'], $this->audit());
        $this->expectAccess('conflict', fn () => $x->cancel($this->actor('baristaA'), $byBarista['expenseId'], $this->audit()));

        $owner = $x->list($this->actor('ownerA'), $this->outlet(), null);
        $this->assertSame(['approved' => 155_000, 'pending' => 0, 'count' => 3], $owner['totals']);
        $this->assertSame([['maintenance', 150_000], ['parkir', 5_000]], array_map(fn ($c) => [$c['category'], $c['amount']], $owner['byCategory']));
        $this->assertSame(155_000, app(SalesReport::class)->report($this->actor('ownerA'), $this->outlet(), $this->today(), $this->today())['summary']['expenses']);

        // Bukti: pemohon & peninjau boleh, staf lain dan tenant lain tidak.
        $this->assertStringStartsWith("\xFF\xD8\xFF", $x->evidence($this->actor('ownerA'), $byCashier['expenseId'])['bytes']);
        $this->assertSame('image/jpeg', $x->evidence($this->actor('cashierA'), $byCashier['expenseId'])['contentType']);
        $this->expectAccess('not_found', fn () => $x->evidence($this->actor('baristaA'), $byCashier['expenseId']));
        $this->expectAccess('not_found', fn () => $x->evidence($this->actor('superB'), $byCashier['expenseId']));
        $this->expectAccess('not_found', fn () => $x->decide($this->actor('superB'), ['approvalId' => $byCashier['approvalId'], 'approve' => true], $this->audit()));

        // Batalkan milik sendiri yang masih menunggu.
        $later = $x->create($this->actor('cashierA'), [...$base, 'amount' => '3000', 'description' => 'Parkir kedua'], null, $this->audit());
        $this->expectAccess('not_found', fn () => $x->cancel($this->actor('baristaA'), $later['expenseId'], $this->audit()));
        $x->cancel($this->actor('cashierA'), $later['expenseId'], $this->audit());
        $this->assertSame('cancelled', DB::table('approvals')->where('id', $later['approvalId'])->value('status'));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'expense.cancelled', $later['expenseId']));
    }
}
