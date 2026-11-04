<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M4: sesi kas, pembayaran tunai, nomor antrean, KDS, pembatalan, approval, refund,
 * dan inventory guard. Padanan bagian pembayaran/KDS/pembatalan tests/integration/orders.test.ts
 * dan batch1-pos.test.ts, ditambah uji konkurensi dari owner.
 */
class PaymentFlowTest extends LiveTestCase
{
    use RunsConcurrently;

    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('paya');
        $B = $ctx->createCompany('payb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'manager2A' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $product = fn (string $name, int $price) => $catalog->createProduct($admin, $A['brandId'], [
            'name' => $name, 'categoryId' => $coffee, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];
        $latte = $product('Iced Latte', 25000);
        $tea = $product('Iced Tea', 15000);
        $stocked = $product('Roti Stok QA', 20000);

        // Produk jadi yang tertaut stok di outlet A (stok cukup, jadi order bisa dibuat).
        $item = DB::table('inventory_items')->insertGetId([
            'company_id' => $A['id'], 'brand_id' => $A['brandId'], 'outlet_id' => $A['outletId'],
            'sku' => 'STK-QA-'.$ctx->runId, 'name' => 'Roti Stok QA', 'kind' => 'finished_good', 'unit' => 'pcs', 'stock_qty' => 10,
        ]);
        DB::table('outlet_products')->updateOrInsert(['outlet_id' => $A['outletId'], 'product_id' => $stocked], ['inventory_item_id' => $item, 'is_available' => true]);

        // Meja resmi untuk pesanan QR meja dine in (sejak daftar meja, EXT-026).
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'T5']);

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $latte, 'tea' => $tea, 'stocked' => $stocked, 'item' => $item,
            'prefix' => DB::table('outlets')->where('id', $A['outletId'])->value('order_number_prefix')];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function flow(): OrderWorkflow
    {
        return app(OrderWorkflow::class);
    }

    private function cash(): CashSessions
    {
        return app(CashSessions::class);
    }

    private function outletId(): string
    {
        return static::$fixture['A']['outletId'];
    }

    /** Order POS belum dibayar. */
    private function order(array $lines = [['latte', 1]], array $overrides = []): array
    {
        return app(OrderEngine::class)->create('pos', ['id' => $this->outletId()], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'customerName' => 'Andi',
            'paymentMethod' => 'cash',
            'lines' => array_map(fn ($l) => ['productId' => static::$fixture[$l[0]], 'modifierIds' => [], 'quantity' => $l[1]], $lines),
            ...$overrides,
        ], $this->audit());
    }

    private function pay(string $orderId, int $tendered = 100_000, string $actor = 'cashierA'): array
    {
        return $this->flow()->confirmCash($this->actor($actor), ['orderId' => $orderId, 'tenderedAmount' => $tendered], $this->audit());
    }

    /** Sesi kas baru: sesi yang masih terbuka ditutup cocok dulu oleh manager. */
    private function freshSession(int $opening = 100_000): string
    {
        $open = DB::table('cash_sessions')->where('outlet_id', $this->outletId())->where('status', 'open')->first();
        if ($open !== null) {
            $this->cash()->close($this->actor('managerA'), ['sessionId' => $open->id, 'actualAmount' => CashSessions::totals($open)['expected']], $this->audit());
        }

        return $this->cash()->open($this->actor('cashierA'), ['outletId' => $this->outletId(), 'openingAmount' => $opening], $this->audit())['id'];
    }

    private function cashSession(string $id): object
    {
        return DB::table('cash_sessions')->where('id', $id)->first();
    }

    private function counter(): int
    {
        return (int) DB::table('order_number_counters')->where('outlet_id', $this->outletId())->max('last_number');
    }

    private function row(string $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->first();
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

    /** Paid order lewat jalur normal: order lalu bayar. */
    private function paidOrder(array $lines = [['latte', 1]]): array
    {
        $created = $this->order($lines);
        $paid = $this->pay($created['orderId']);

        return [...$created, ...$paid];
    }

    // --- Sesi kas -----------------------------------------------------------------------

    public function test_buka_tutup_kas_rekonsiliasi_dan_audit(): void
    {
        $A = static::$fixture['A'];
        $sessionId = $this->freshSession(100_000);
        $this->expectAccess('conflict', fn () => $this->cash()->open($this->actor('managerA'), ['outletId' => $this->outletId(), 'openingAmount' => 50_000], $this->audit()), '/sudah dibuka/');
        $this->assertSame(1, DB::table('cash_sessions')->where('outlet_id', $this->outletId())->where('status', 'open')->count());

        $this->cash()->recordMovement($this->actor('cashierA'), ['outletId' => $this->outletId(), 'type' => 'cash_in', 'amount' => 20_000, 'reason' => 'tambah uang kecil'], $this->audit());
        $this->cash()->recordMovement($this->actor('cashierA'), ['outletId' => $this->outletId(), 'type' => 'cash_out', 'amount' => '5000', 'reason' => 'beli es batu'], $this->audit());
        $this->expectAccess('invalid', fn () => $this->cash()->recordMovement($this->actor('cashierA'), ['outletId' => $this->outletId(), 'type' => 'cash_out', 'amount' => 10_000_000, 'reason' => 'terlalu besar'], $this->audit()), '/^Kas keluar melebihi saldo laci Rp 115\.000\.$/');
        $this->expectAccess('invalid', fn () => $this->cash()->recordMovement($this->actor('cashierA'), ['outletId' => $this->outletId(), 'type' => 'cash_in', 'amount' => 0, 'reason' => 'nol'], $this->audit()), '/lebih dari 0/');
        $this->expectAccess('invalid', fn () => $this->cash()->recordMovement($this->actor('cashierA'), ['outletId' => $this->outletId(), 'type' => 'cash_in', 'amount' => 1000, 'reason' => 'ab'], $this->audit()), '/Alasan wajib/');

        $paid = $this->paidOrder();
        $this->assertSame(75_000, $paid['change']);
        $totals = CashSessions::totals($this->cashSession($sessionId));
        $this->assertSame(['openingAmount' => 100_000, 'cashSales' => 25_000, 'cashIn' => 20_000, 'cashOut' => 5_000, 'refunds' => 0, 'expected' => 140_000], $totals);

        $this->expectAccess('invalid', fn () => $this->cash()->close($this->actor('cashierA'), ['sessionId' => $sessionId, 'actualAmount' => 139_000], $this->audit()), '/^Ada selisih -Rp 1\.000\. Alasan wajib diisi\.$/');
        $this->expectAccess('not_found', fn () => $this->cash()->close($this->actor('superB'), ['sessionId' => $sessionId, 'actualAmount' => 140_000], $this->audit()));
        $closed = $this->cash()->close($this->actor('cashierA'), ['sessionId' => $sessionId, 'actualAmount' => 139_000, 'reason' => 'uang receh hilang'], $this->audit());
        $this->assertSame(['expected' => 140_000, 'actual' => 139_000, 'variance' => -1_000], $closed);
        $row = $this->cashSession($sessionId);
        $this->assertSame(['closed', 140_000, 139_000, 'uang receh hilang', static::$fixture['cashierA']['id']], [$row->status, (int) $row->expected_amount, (int) $row->actual_amount, $row->variance_reason, $row->closed_by]);
        $this->expectAccess('conflict', fn () => $this->cash()->close($this->actor('cashierA'), ['sessionId' => $sessionId, 'actualAmount' => 139_000, 'reason' => 'lagi'], $this->audit()), '/sudah ditutup/');

        // Sesi tertutup tidak menerima pembayaran baru.
        $unpaid = $this->order();
        $this->expectAccess('conflict', fn () => $this->pay($unpaid['orderId']), '/^'.preg_quote(CashSessions::NO_SESSION_MESSAGE, '/').'$/');
        $this->assertSame('pending_payment', $this->row($unpaid['orderId'])->status);

        foreach (['cash.session_opened' => 1, 'cash.money_in' => 1, 'cash.money_out' => 1, 'cash.session_closed' => 1] as $action => $count) {
            $this->assertSame($count, $this->ctx()->countAudit($A['id'], $action, $sessionId), $action);
        }
        $log = DB::table('audit_logs')->where('entity_id', $sessionId)->where('action', 'cash.session_closed')->first();
        $this->assertEquals(['status' => 'closed', 'openingAmount' => 100_000, 'cashSales' => 25_000, 'cashIn' => 20_000, 'cashOut' => 5_000, 'refunds' => 0, 'expected' => 140_000, 'actual' => 139_000, 'variance' => -1_000], json_decode($log->after, true));
    }

    public function test_izin_kas(): void
    {
        $this->expectAccess('forbidden', fn () => $this->cash()->open($this->actor('baristaA'), ['outletId' => $this->outletId(), 'openingAmount' => 0], $this->audit()));
        $this->expectAccess('forbidden', fn () => $this->cash()->open($this->actor('ownerA'), ['outletId' => $this->outletId(), 'openingAmount' => 0], $this->audit()));
        $this->expectAccess('forbidden', fn () => $this->cash()->recordMovement($this->actor('baristaA'), ['outletId' => $this->outletId(), 'type' => 'cash_in', 'amount' => 1000, 'reason' => 'coba'], $this->audit()));
        $this->expectAccess('forbidden', fn () => $this->cash()->overview($this->actor('baristaA'), ['id' => $this->outletId(), 'timezone' => 'Asia/Jakarta']));
        // Outlet company lain tidak dikenali (bukan sekadar tidak berizin).
        $this->expectAccess('not_found', fn () => $this->cash()->open($this->actor('superB'), ['outletId' => $this->outletId(), 'openingAmount' => 0], $this->audit()));
        $this->expectAccess('invalid', fn () => $this->cash()->open($this->actor('cashierA'), ['outletId' => $this->outletId(), 'openingAmount' => -1], $this->audit()), '/tidak boleh negatif/');
        $this->expectAccess('invalid', fn () => $this->cash()->open($this->actor('cashierA'), ['outletId' => $this->outletId(), 'openingAmount' => '12.5'], $this->audit()), '/bilangan bulat/');
        // Owner boleh melihat ringkasan kas.
        $this->assertArrayHasKey('history', $this->cash()->overview($this->actor('ownerA'), ['id' => $this->outletId(), 'timezone' => 'Asia/Jakarta']));
    }

    // --- Pembayaran dan antrean ----------------------------------------------------------

    public function test_bayar_tunai_nomor_antrean_dan_idempotent(): void
    {
        $A = static::$fixture['A'];
        $sessionId = $this->freshSession();
        $created = $this->order([['latte', 2], ['tea', 1]]);
        $this->expectAccess('invalid', fn () => $this->pay($created['orderId'], 64_999), '/^Uang diterima kurang dari total Rp 65\.000\.$/');
        $this->expectAccess('invalid', fn () => $this->pay($created['orderId'], -5), '/tidak boleh negatif/');
        $before = $this->counter();

        $paid = $this->pay($created['orderId'], 70_000);
        $queue = $before + 1;
        $this->assertSame([static::$fixture['prefix'].'-'.str_pad((string) $queue, 3, '0', STR_PAD_LEFT), 5_000, false], [$paid['orderNumber'], $paid['change'], $paid['alreadyPaid']]);
        $row = $this->row($created['orderId']);
        $this->assertSame(['queued', 'paid', $queue, $paid['orderNumber']], [$row->status, $row->payment_status, (int) $row->queue_number, $row->order_number]);
        $this->assertNotNull($row->paid_at);
        $payment = DB::table('payments')->where('order_id', $row->id)->get();
        $this->assertCount(1, $payment);
        $this->assertSame(['cash', 'paid', 65_000, 70_000, 5_000, $sessionId, static::$fixture['cashierA']['id']], [$payment[0]->method, $payment[0]->status, (int) $payment[0]->amount, (int) $payment[0]->tendered_amount, (int) $payment[0]->change_amount, $payment[0]->cash_session_id, $payment[0]->confirmed_by]);
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'payment.confirmed', $row->id));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'order.queued', $row->id));

        // Retry / double click: tidak ada pembayaran atau nomor kedua.
        $again = $this->pay($created['orderId']);
        $this->assertSame([$paid['orderNumber'], null, true], [$again['orderNumber'], $again['change'], $again['alreadyPaid']]);
        $this->assertSame($queue, $this->counter());
        $this->assertSame(1, DB::table('payments')->where('order_id', $row->id)->count());
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'payment.confirmed', $row->id));

        // Pelanggan melihat status "Pesanan diterima" (queued) dengan nomor antrean.
        $token = DB::table('orders')->where('id', $row->id)->value('access_token_hash');
        $this->assertNull($token, 'order POS tidak punya token guest');
    }

    public function test_izin_dan_isolasi_pembayaran(): void
    {
        $this->freshSession();
        $created = $this->order();
        $this->expectAccess('forbidden', fn () => $this->pay($created['orderId'], 100_000, 'baristaA'));
        $this->expectAccess('forbidden', fn () => $this->pay($created['orderId'], 100_000, 'ownerA'));
        $this->expectAccess('not_found', fn () => $this->pay($created['orderId'], 100_000, 'superB'));
        $this->expectAccess('invalid', fn () => $this->flow()->confirmCash($this->actor('cashierA'), ['orderId' => 'bukan-uuid', 'tenderedAmount' => 1], $this->audit()));
        $this->assertSame(['pending_payment', 'pending'], [$this->row($created['orderId'])->status, $this->row($created['orderId'])->payment_status]);
        $this->assertSame(0, DB::table('payments')->where('order_id', $created['orderId'])->where('status', 'paid')->count());
    }

    public function test_pembayaran_bersamaan_nomor_antrean_unik_dan_berurutan(): void
    {
        $this->freshSession();
        $orders = array_map(fn () => $this->order(), range(1, 4));
        $before = $this->counter();
        $results = $this->concurrently(array_map(fn ($o) => ['op' => 'pay', 'actorId' => static::$fixture['cashierA']['id'], 'input' => ['orderId' => $o['orderId'], 'tenderedAmount' => 25_000]], $orders));

        $this->assertSame([true, true, true, true], array_column($results, 'ok'), json_encode($results));
        $rows = DB::table('orders')->whereIn('id', array_column($orders, 'orderId'))->orderBy('queue_number')->get(['queue_number', 'order_number', 'paid_at']);
        $this->assertSame(range($before + 1, $before + 4), $rows->pluck('queue_number')->map(fn ($n) => (int) $n)->all());
        $this->assertCount(4, $rows->pluck('order_number')->unique());
        // Urutan waktu bayar sama dengan urutan nomor antrean (KDS FIFO deterministik).
        $paidAt = $rows->pluck('paid_at')->all();
        $sorted = $paidAt;
        sort($sorted);
        $this->assertSame($sorted, $paidAt);
        $this->assertSame($before + 4, $this->counter());
    }

    public function test_double_payment_bersamaan_satu_pembayaran_satu_nomor(): void
    {
        $this->freshSession();
        $created = $this->order();
        $before = $this->counter();
        $job = ['op' => 'pay', 'actorId' => static::$fixture['cashierA']['id'], 'input' => ['orderId' => $created['orderId'], 'tenderedAmount' => 50_000]];
        $results = $this->concurrently([$job, $job, $job]);

        $this->assertSame([true, true, true], array_column($results, 'ok'), json_encode($results));
        $paid = array_column($results, 'result');
        $this->assertCount(1, array_filter($paid, fn ($p) => ! $p['alreadyPaid']));
        $this->assertCount(1, array_unique(array_column($paid, 'orderNumber')));
        $this->assertSame($before + 1, $this->counter());
        $this->assertSame(1, DB::table('payments')->where('order_id', $created['orderId'])->count());
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'payment.confirmed', $created['orderId']));
    }

    public function test_pembayaran_bersamaan_dengan_tutup_kas_tetap_konsisten(): void
    {
        $sessionId = $this->freshSession(50_000);
        $created = $this->order();
        // Uang fisik = saldo bila pembayaran masuk lebih dulu. Bila tutup kas menang,
        // pembayaran ditolak dan selisih +25.000 tercatat dengan alasan.
        $results = $this->concurrently([
            ['op' => 'pay', 'actorId' => static::$fixture['cashierA']['id'], 'input' => ['orderId' => $created['orderId'], 'tenderedAmount' => 25_000]],
            ['op' => 'close', 'actorId' => static::$fixture['managerA']['id'], 'input' => ['sessionId' => $sessionId, 'actualAmount' => 75_000, 'reason' => 'uji bersamaan']],
        ]);
        [$pay, $close] = $results;
        $this->assertTrue($close['ok'], json_encode($close));
        $session = $this->cashSession($sessionId);
        $sales = (int) DB::table('payments')->where('cash_session_id', $sessionId)->where('status', 'paid')->sum('amount');
        // Invariant: saldo seharusnya saat ditutup = modal + penjualan yang terikat ke sesi itu.
        $this->assertSame(50_000 + $sales, (int) $session->expected_amount);
        if ($pay['ok']) {
            $this->assertSame([25_000, 75_000], [$sales, (int) $session->expected_amount]);
            $this->assertSame('queued', $this->row($created['orderId'])->status);
        } else {
            $this->assertSame([CashSessions::NO_SESSION_MESSAGE, 0], [$pay['message'], $sales]);
            $this->assertSame(['pending_payment', null], [$this->row($created['orderId'])->status, $this->row($created['orderId'])->queue_number]);
        }
    }

    public function test_pembayaran_order_kedaluwarsa_ditolak_dan_expiry_tercatat(): void
    {
        $this->freshSession();
        $created = $this->order();
        DB::table('orders')->where('id', $created['orderId'])->update(['expires_at' => DB::raw("now() - interval '1 minute'")]);
        $before = $this->counter();

        $this->expectAccess('conflict', fn () => $this->pay($created['orderId']), '/^'.preg_quote(OrderWorkflow::EXPIRED_MESSAGE, '/').'$/');
        $row = $this->row($created['orderId']);
        // Pembatalan otomatis ikut ter-commit walau pembayaran ditolak.
        $this->assertSame(['cancelled', 'expired', null], [$row->status, $row->payment_status, $row->queue_number]);
        $this->assertSame('expired', DB::table('payments')->where('order_id', $row->id)->value('status'));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'order.expired', $row->id));
        $this->assertSame($before, $this->counter());
        // Retry berikutnya tetap ditolak dengan pesan yang sama.
        $this->expectAccess('conflict', fn () => $this->pay($created['orderId']), '/sudah habis/');
    }

    // --- Inventory guard -----------------------------------------------------------------

    public function test_inventory_guard_menolak_tanpa_perubahan_apa_pun(): void
    {
        $sessionId = $this->freshSession();
        $created = $this->order([['stocked', 1], ['tea', 1]]);
        $before = [
            'counter' => $this->counter(),
            'totals' => CashSessions::totals($this->cashSession($sessionId)),
            'movements' => DB::table('cash_movements')->where('cash_session_id', $sessionId)->count(),
            'payments' => DB::table('payments')->where('order_id', $created['orderId'])->get(['status', 'cash_session_id', 'tendered_amount', 'paid_at'])->toArray(),
            'order' => (array) $this->row($created['orderId']),
            'stock' => DB::table('inventory_items')->where('id', static::$fixture['item'])->value('stock_qty'),
            'link' => DB::table('outlet_products')->where('outlet_id', $this->outletId())->where('product_id', static::$fixture['stocked'])->value('inventory_item_id'),
        ];

        $this->expectAccess('conflict', fn () => $this->pay($created['orderId']), '/^Pembayaran belum bisa diproses: Roti Stok QA terhubung ke stok, dan pemotongan stok otomatis belum tersedia\./');

        $this->assertSame($before['counter'], $this->counter());
        $this->assertSame($before['totals'], CashSessions::totals($this->cashSession($sessionId)));
        $this->assertSame($before['movements'], DB::table('cash_movements')->where('cash_session_id', $sessionId)->count());
        $this->assertEquals($before['payments'], DB::table('payments')->where('order_id', $created['orderId'])->get(['status', 'cash_session_id', 'tendered_amount', 'paid_at'])->toArray());
        $this->assertSame($before['order'], (array) $this->row($created['orderId']));
        $this->assertSame('pending_payment', $before['order']['status']);
        $this->assertSame($before['stock'], DB::table('inventory_items')->where('id', static::$fixture['item'])->value('stock_qty'));
        $this->assertSame(static::$fixture['item'], $before['link'], 'tautan stok tidak diubah');
        $this->assertSame(0, $this->ctx()->countAudit(static::$fixture['A']['id'], 'payment.confirmed', $created['orderId']));

        // Order tanpa produk tertaut tetap bisa dibayar normal.
        $this->assertFalse($this->pay($this->order([['tea', 1]])['orderId'])['alreadyPaid']);
    }

    public function test_inventory_guard_bersamaan_tidak_ada_partial_commit(): void
    {
        $sessionId = $this->freshSession();
        $created = $this->order([['stocked', 2]]);
        $before = $this->counter();
        $job = ['op' => 'pay', 'actorId' => static::$fixture['cashierA']['id'], 'input' => ['orderId' => $created['orderId'], 'tenderedAmount' => 40_000]];
        $results = $this->concurrently([$job, $job]);

        $this->assertSame([false, false], array_column($results, 'ok'));
        $this->assertSame(['conflict', 'conflict'], array_column($results, 'reason'));
        $this->assertSame($before, $this->counter());
        $this->assertSame(0, DB::table('payments')->where('cash_session_id', $sessionId)->where('order_id', $created['orderId'])->count());
        $this->assertSame(['pending_payment', 'pending'], [$this->row($created['orderId'])->status, $this->row($created['orderId'])->payment_status]);
    }

    // --- KDS -------------------------------------------------------------------------------

    public function test_kds_hanya_order_lunas_dan_transisi_status(): void
    {
        $A = static::$fixture['A'];
        $this->freshSession();
        $outlet = ['id' => $this->outletId(), 'timezone' => 'Asia/Jakarta'];
        $unpaid = $this->order();
        $paid = $this->paidOrder([['latte', 1], ['tea', 2]]);
        $board = fn () => collect(app(OrderEngine::class)->kdsBoard($this->actor('baristaA'), $outlet))->keyBy('id');

        $this->assertArrayNotHasKey($unpaid['orderId'], $board()->all(), 'order belum dibayar tidak boleh masuk KDS');
        $card = $board()[$paid['orderId']];
        $this->assertSame(['queued', $paid['orderNumber']], [$card['status'], $card['orderNumber']]);
        $lines = array_map(fn ($l) => [$l['productName'], $l['quantity']], $card['lines']);
        sort($lines);
        $this->assertSame([['Iced Latte', 1], ['Iced Tea', 2]], $lines);

        $move = fn (string $from, string $to, string $actor = 'baristaA') => $this->flow()->updateStatus($this->actor($actor), ['orderId' => $paid['orderId'], 'from' => $from, 'to' => $to], $this->audit());
        // Order belum dibayar tidak bisa didorong ke antrean lewat KDS.
        $this->expectAccess('invalid', fn () => $this->flow()->updateStatus($this->actor('baristaA'), ['orderId' => $unpaid['orderId'], 'from' => 'pending_payment', 'to' => 'queued'], $this->audit()));
        // Pengaman kedua: status antre tanpa pembayaran lunas tetap ditolak dan tidak tampil di KDS.
        DB::table('orders')->where('id', $unpaid['orderId'])->update(['status' => 'queued']);
        $this->expectAccess('conflict', fn () => $this->flow()->updateStatus($this->actor('baristaA'), ['orderId' => $unpaid['orderId'], 'from' => 'queued', 'to' => 'preparing'], $this->audit()), '/belum dibayar/');
        $this->assertArrayNotHasKey($unpaid['orderId'], $board()->all());
        $this->expectAccess('invalid', fn () => $move('queued', 'ready'), '/tidak diizinkan/');
        $this->expectAccess('forbidden', fn () => $move('queued', 'preparing', 'cashierA'));
        $this->expectAccess('not_found', fn () => $move('queued', 'preparing', 'superB'));

        $move('queued', 'preparing');
        $this->assertNotNull($this->row($paid['orderId'])->preparing_at);
        // Double click dengan status lama: ditolak, tidak maju dua langkah.
        $this->expectAccess('conflict', fn () => $move('queued', 'preparing'), '/sudah berubah/');
        $move('preparing', 'ready');
        $this->assertNotNull($this->row($paid['orderId'])->ready_at);
        // Koreksi mundur butuh kds.manage.
        $this->expectAccess('forbidden', fn () => $move('ready', 'preparing'));
        $move('ready', 'preparing', 'managerA');
        $this->assertNull($this->row($paid['orderId'])->ready_at);
        $move('preparing', 'ready');
        $move('ready', 'completed');
        $row = $this->row($paid['orderId']);
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);
        $this->assertSame('completed', $board()[$paid['orderId']]['status']);
        $this->expectAccess('invalid', fn () => $move('completed', 'queued'));

        foreach (['order.preparing' => 1, 'order.ready' => 2, 'order.completed' => 1, 'order.status_override' => 1] as $action => $count) {
            $this->assertSame($count, $this->ctx()->countAudit($A['id'], $action, $paid['orderId']), $action);
        }
        $this->expectAccess('forbidden', fn () => app(OrderEngine::class)->kdsBoard($this->actor('cashierA'), $outlet));
    }

    public function test_status_pelanggan_mengikuti_pembayaran_dan_kds(): void
    {
        $this->freshSession();
        $guest = app(OrderEngine::class)->create('qr_table', ['code' => static::$fixture['A']['outletCode']], null, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => 'T5', 'customerName' => 'Tamu', 'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['tea'], 'modifierIds' => [], 'quantity' => 1]],
        ], $this->audit());
        $view = fn () => app(OrderEngine::class)->guestOrder($guest['orderId'], $guest['accessToken']);
        $this->assertSame(['pending_payment', null], [$view()['status'], $view()['orderNumber']]);
        $this->assertSame('order:'.substr(DB::table('orders')->where('id', $guest['orderId'])->value('access_token_hash'), 0, 40), $view()['topic']);

        $paid = $this->pay($guest['orderId']);
        $this->assertSame(['queued', 'paid', $paid['orderNumber']], [$view()['status'], $view()['paymentStatus'], $view()['orderNumber']]);
        foreach ([['queued', 'preparing'], ['preparing', 'ready'], ['ready', 'completed']] as [$from, $to]) {
            $this->flow()->updateStatus($this->actor('baristaA'), ['orderId' => $guest['orderId'], 'from' => $from, 'to' => $to], $this->audit());
            $this->assertSame($to, $view()['status']);
        }
        // Sinyal realtime dikirim ke topik guest (tanpa data) saat status berubah.
        $this->assertGreaterThanOrEqual(4, DB::table('realtime.messages')->where('topic', $view()['topic'])->count());
    }

    // --- Pembatalan, approval, refund -------------------------------------------------------

    public function test_batal_order_belum_dibayar(): void
    {
        $created = $this->order();
        $this->expectAccess('invalid', fn () => $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $created['orderId'], 'reason' => 'ab'], $this->audit()), '/minimal 3/');
        $this->expectAccess('invalid', fn () => $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $created['orderId']], $this->audit()), '/Alasan wajib/');
        $this->expectAccess('forbidden', fn () => $this->flow()->cancel($this->actor('cashierA'), ['orderId' => $created['orderId'], 'reason' => 'salah input'], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->flow()->cancel($this->actor('superB'), ['orderId' => $created['orderId'], 'reason' => 'salah input'], $this->audit()));

        $this->assertSame(['result' => 'cancelled'], $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $created['orderId'], 'reason' => 'customer batal'], $this->audit()));
        $row = $this->row($created['orderId']);
        $this->assertSame(['cancelled', 'expired', 'customer batal'], [$row->status, $row->payment_status, $row->cancel_reason]);
        $this->assertSame('expired', DB::table('payments')->where('order_id', $row->id)->value('status'));
        $this->freshSession();
        $this->expectAccess('conflict', fn () => $this->pay($created['orderId']));
        $this->expectAccess('conflict', fn () => $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $created['orderId'], 'reason' => 'lagi'], $this->audit()), '/sudah selesai atau dibatalkan/');
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'order.cancelled', $row->id));
    }

    public function test_batal_order_lunas_approval_tanpa_self_approval_lalu_refund(): void
    {
        $A = static::$fixture['A'];
        $sessionId = $this->freshSession(10_000);
        $paid = $this->paidOrder();

        // Manager mengajukan, lalu tidak bisa menyetujui sendiri.
        $this->assertSame(['result' => 'approval_requested'], $this->flow()->cancel($this->actor('managerA'), ['orderId' => $paid['orderId'], 'reason' => 'customer komplain'], $this->audit()));
        $this->expectAccess('conflict', fn () => $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $paid['orderId'], 'reason' => 'dobel'], $this->audit()), '/menunggu persetujuan/');
        $approvalId = DB::table('approvals')->where('reference_id', $paid['orderId'])->value('id');
        $detail = app(OrderEngine::class)->detail($this->actor('managerA'), $paid['orderId']);
        $this->assertSame([$approvalId, static::$fixture['managerA']['id']], [$detail['pendingApproval']['id'], $detail['pendingApproval']['requestedBy']]);
        $decide = fn (string $actor, bool $approve) => $this->flow()->decideCancellation($this->actor($actor), ['approvalId' => $approvalId, 'approve' => $approve], $this->audit());
        $this->expectAccess('forbidden', fn () => $decide('managerA', true), '/pengajuan Anda sendiri/');
        $this->expectAccess('forbidden', fn () => $decide('baristaA', true));
        $this->expectAccess('not_found', fn () => $decide('superB', true));
        $this->assertSame('queued', $this->row($paid['orderId'])->status, 'belum disetujui: order tetap jalan');

        // Refund sebelum disetujui ditolak.
        $this->expectAccess('conflict', fn () => $this->flow()->refundCash($this->actor('managerA'), ['orderId' => $paid['orderId']], $this->audit()), '/pembatalannya sudah disetujui/');

        $this->assertSame(['approved' => true], $decide('manager2A', true));
        $this->expectAccess('conflict', fn () => $decide('manager2A', false), '/sudah diputuskan/');
        $row = $this->row($paid['orderId']);
        // Dibatalkan, uang belum otomatis kembali.
        $this->assertSame(['cancelled', 'paid', 'customer komplain'], [$row->status, $row->payment_status, $row->cancel_reason]);
        $this->assertSame('approved', DB::table('approvals')->where('id', $approvalId)->value('status'));

        // Refund: kasir tanpa payment.refund ditolak; manager mencatat dari sesi kas terbuka.
        $this->expectAccess('forbidden', fn () => $this->flow()->refundCash($this->actor('cashierA'), ['orderId' => $paid['orderId']], $this->audit()));
        $this->assertSame(['amount' => 25_000], $this->flow()->refundCash($this->actor('managerA'), ['orderId' => $paid['orderId']], $this->audit()));
        $this->expectAccess('conflict', fn () => $this->flow()->refundCash($this->actor('managerA'), ['orderId' => $paid['orderId']], $this->audit()), '/sudah tercatat/');

        $refund = DB::table('refunds')->where('order_id', $paid['orderId'])->get();
        $this->assertCount(1, $refund);
        $this->assertSame([25_000, 'customer komplain', $approvalId, static::$fixture['managerA']['id']], [(int) $refund[0]->amount, $refund[0]->reason, $refund[0]->approval_id, $refund[0]->created_by]);
        $movement = DB::table('cash_movements')->where('refund_id', $refund[0]->id)->first();
        $this->assertSame(['refund', 25_000, $sessionId], [$movement->type, (int) $movement->amount, $movement->cash_session_id]);
        $this->assertSame('refunded', DB::table('payments')->where('order_id', $paid['orderId'])->value('status'));
        $this->assertSame(['cancelled', 'refunded'], [$this->row($paid['orderId'])->status, $this->row($paid['orderId'])->payment_status]);
        // Rekonsiliasi: penjualan tetap tercatat, refund keluar dari laci.
        $this->assertSame(['openingAmount' => 10_000, 'cashSales' => 25_000, 'cashIn' => 0, 'cashOut' => 0, 'refunds' => 25_000, 'expected' => 10_000], CashSessions::totals($this->cashSession($sessionId)));
        foreach (['order.cancel_requested' => 1, 'approval.approved' => 1, 'order.cancelled' => 1, 'payment.refunded' => 1] as $action => $count) {
            $this->assertSame($count, $this->ctx()->countAudit($A['id'], $action, $paid['orderId']), $action);
        }
    }

    public function test_tolak_pembatalan_order_tetap_berjalan(): void
    {
        $this->freshSession();
        $paid = $this->paidOrder();
        $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $paid['orderId'], 'reason' => 'salah tekan'], $this->audit());
        $approvalId = DB::table('approvals')->where('reference_id', $paid['orderId'])->value('id');
        $this->assertSame(['approved' => false], $this->flow()->decideCancellation($this->actor('managerA'), ['approvalId' => $approvalId, 'approve' => false, 'note' => 'order sudah dibuat'], $this->audit()));
        $this->assertSame(['queued', 'paid'], [$this->row($paid['orderId'])->status, $this->row($paid['orderId'])->payment_status]);
        $this->assertSame(['rejected', 'order sudah dibuat'], array_values((array) DB::table('approvals')->where('id', $approvalId)->first(['status', 'decision_note'])));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'approval.rejected', $paid['orderId']));
    }

    public function test_refund_butuh_sesi_kas_terbuka_dan_saldo_cukup(): void
    {
        $this->freshSession();
        $paid = $this->paidOrder();
        $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $paid['orderId'], 'reason' => 'customer batal'], $this->audit());
        $this->flow()->decideCancellation($this->actor('managerA'), ['approvalId' => DB::table('approvals')->where('reference_id', $paid['orderId'])->value('id'), 'approve' => true], $this->audit());

        // Kas ditutup: refund ditolak tanpa mengubah apa pun.
        $open = DB::table('cash_sessions')->where('outlet_id', $this->outletId())->where('status', 'open')->first();
        $this->cash()->close($this->actor('managerA'), ['sessionId' => $open->id, 'actualAmount' => CashSessions::totals($open)['expected']], $this->audit());
        $this->expectAccess('conflict', fn () => $this->flow()->refundCash($this->actor('managerA'), ['orderId' => $paid['orderId']], $this->audit()), '/Sesi kas belum dibuka/');

        // Laci baru dengan modal 0: saldo tidak cukup.
        $this->cash()->open($this->actor('cashierA'), ['outletId' => $this->outletId(), 'openingAmount' => 0], $this->audit());
        $this->expectAccess('invalid', fn () => $this->flow()->refundCash($this->actor('managerA'), ['orderId' => $paid['orderId']], $this->audit()), '/^Saldo laci Rp 0 tidak cukup untuk refund\.$/');
        $this->assertSame(0, DB::table('refunds')->where('order_id', $paid['orderId'])->count());
        $this->assertSame('paid', $this->row($paid['orderId'])->payment_status);

        // Modal cukup di sesi baru: refund keluar dari sesi yang sedang terbuka.
        $sessionId = $this->freshSession(30_000);
        $this->flow()->refundCash($this->actor('managerA'), ['orderId' => $paid['orderId']], $this->audit());
        $this->assertSame($sessionId, DB::table('cash_movements')->where('type', 'refund')->where('outlet_id', $this->outletId())->orderByDesc('created_at')->value('cash_session_id'));
        $this->assertSame(5_000, CashSessions::totals($this->cashSession($sessionId))['expected']);
    }

    public function test_double_refund_bersamaan_dan_refund_vs_tutup_kas(): void
    {
        $sessionId = $this->freshSession(100_000);
        $first = $this->paidOrder();
        $second = $this->paidOrder();
        foreach ([$first, $second] as $order) {
            $this->flow()->cancel($this->actor('baristaA'), ['orderId' => $order['orderId'], 'reason' => 'uji refund'], $this->audit());
            $this->flow()->decideCancellation($this->actor('managerA'), ['approvalId' => DB::table('approvals')->where('reference_id', $order['orderId'])->value('id'), 'approve' => true], $this->audit());
        }

        $job = ['op' => 'refund', 'actorId' => static::$fixture['managerA']['id'], 'input' => ['orderId' => $first['orderId']]];
        $results = $this->concurrently([$job, $job]);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertSame(['conflict'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')));
        $this->assertSame(1, DB::table('refunds')->where('order_id', $first['orderId'])->count());
        $this->assertSame(1, DB::table('cash_movements')->where('type', 'refund')->whereIn('refund_id', DB::table('refunds')->where('order_id', $first['orderId'])->select('id'))->count());

        // Refund vs tutup kas bersamaan: uang fisik dihitung seolah refund sudah keluar.
        $expectedAfterRefund = CashSessions::totals($this->cashSession($sessionId))['expected'] - 25_000;
        [$refund, $close] = $this->concurrently([
            ['op' => 'refund', 'actorId' => static::$fixture['managerA']['id'], 'input' => ['orderId' => $second['orderId']]],
            ['op' => 'close', 'actorId' => static::$fixture['manager2A']['id'], 'input' => ['sessionId' => $sessionId, 'actualAmount' => $expectedAfterRefund, 'reason' => 'uji bersamaan']],
        ]);
        $this->assertTrue($close['ok'], json_encode($close));
        $session = $this->cashSession($sessionId);
        $refunds = (int) DB::table('cash_movements')->where('cash_session_id', $sessionId)->where('type', 'refund')->sum('amount');
        $sales = (int) DB::table('payments')->where('cash_session_id', $sessionId)->whereIn('status', ['paid', 'refunded'])->sum('amount');
        // Invariant: saldo seharusnya saat ditutup = modal + penjualan − refund yang tercatat di sesi itu.
        $this->assertSame(100_000 + $sales - $refunds, (int) $session->expected_amount);
        if ($refund['ok']) {
            $this->assertSame(50_000, $refunds);
            $this->assertSame('refunded', $this->row($second['orderId'])->payment_status);
        } else {
            $this->assertSame([CashSessions::NO_SESSION_MESSAGE, 25_000], [$refund['message'], $refunds]);
            $this->assertSame('paid', $this->row($second['orderId'])->payment_status);
        }
    }

    public function test_daftar_lunas_dan_isolasi_detail(): void
    {
        $this->freshSession();
        $paid = $this->paidOrder();
        $unpaid = $this->order();
        $outlet = collect(app(OrderEngine::class)->outletsWithPermission($this->actor('cashierA'), 'order.view'))->firstWhere('id', $this->outletId());
        $list = app(OrderEngine::class)->list($this->actor('cashierA'), $outlet, ['status' => 'paid', 'q' => null, 'page' => 1]);
        $ids = array_column($list['rows'], 'id');
        $this->assertContains($paid['orderId'], $ids);
        $this->assertNotContains($unpaid['orderId'], $ids);
        $this->expectAccess('not_found', fn () => app(OrderEngine::class)->detail($this->actor('superB'), $paid['orderId']));
        $this->expectAccess('not_found', fn () => $this->flow()->refundCash($this->actor('superB'), ['orderId' => $paid['orderId']], $this->audit()));
    }
}
