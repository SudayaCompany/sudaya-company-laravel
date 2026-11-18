<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Permintaan di luar menu (keputusan owner 17 November 2026: "kasir isi harga dulu"): request masuk
 * sebagai baris tanpa harga; kasir mengisi harganya atau menandai tidak tersedia, baru pembayaran
 * (tunai, QRIS, bayar saat diantar) bisa diterima. Total dan tagihan ikut berubah, QRIS pelanggan
 * baru tampil setelah total final, batas bayar dihitung ulang, harga terkunci setelah lunas.
 */
class CustomRequestTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, $this->fakeImages());
        $A = $ctx->createCompany('reqa');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Latte Request', 'categoryId' => $cat, 'basePrice' => '20000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'L1-05']);
        // QRIS statis outlet (gambar tidak perlu ada di Storage untuk alur ini).
        DB::table('outlets')->where('id', $A['outletId'])->update(['qris_image_path' => "qa/{$ctx->runId}/qris.png"]);
        app(CashSessions::class)->open($ctx->load($users['cashierA']['id']), ['outletId' => $A['outletId'], 'openingAmount' => 100_000], $audit);

        return ['A' => $A, ...$users, 'latte' => $latte, 'code' => DB::table('outlets')->where('id', $A['outletId'])->value('code')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MenuImageStorage::class, $this->fakeImages());
    }

    private function fakeImages(): MenuImageStorage
    {
        return new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        };
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function outletId(): string
    {
        return static::$fixture['A']['outletId'];
    }

    private function order(array $overrides = [], string $channel = 'website'): array
    {
        return app(OrderEngine::class)->create($channel, ['code' => static::$fixture['code']], null, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'customerName' => 'Dewi Request', 'department' => 'Keuangan',
            'paymentMethod' => 'qris', 'lines' => [['productId' => static::$fixture['latte'], 'modifierIds' => [], 'quantity' => 1]],
            ...$overrides,
        ], $this->audit());
    }

    private function price(string $actor, string $orderId, array $input): array
    {
        return app(OrderWorkflow::class)->priceCustomRequest($this->actor($actor), ['orderId' => $orderId, ...$input], $this->audit());
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

    public function test_qris_menunggu_harga_kasir_lalu_dibayar_dengan_total_final(): void
    {
        $engine = app(OrderEngine::class);
        $workflow = app(OrderWorkflow::class);
        $key = (string) Str::uuid();
        $order = $this->order(['idempotencyKey' => $key, 'customRequest' => 'Matcha oat milk 1 gelas']);
        $id = $order['orderId'];
        $this->assertSame(20_000, $order['total'], 'total awal hanya menu yang ada di daftar');
        $row = DB::table('orders')->where('id', $id)->first();
        $this->assertSame(['pending_payment', 'Matcha oat milk 1 gelas', 'pending', null, null], [$row->status, $row->custom_request, $row->custom_request_status, $row->custom_request_price, $row->notes]);

        // Idempotensi: kirim ulang yang sama → pesanan sama (token pelanggan diterbitkan ulang, yang
        // lama tidak berlaku); request berbeda dengan kunci yang sama → konflik.
        $replay = $this->order(['idempotencyKey' => $key, 'customRequest' => 'Matcha oat milk 1 gelas']);
        $this->assertSame([true, $id], [$replay['replayed'], $replay['orderId']]);
        $token = $replay['accessToken'];
        $this->expectAccess('conflict', fn () => $this->order(['idempotencyKey' => $key, 'customRequest' => 'Teh tarik']));

        // Pelanggan belum melihat QRIS sebelum total final; kasir melihat tanda perlu harga.
        $guest = $engine->guestOrder($id, $token);
        $this->assertSame([null, ['text' => 'Matcha oat milk 1 gelas', 'status' => 'pending', 'price' => null], 20_000], [$guest['qrisImageUrl'], $guest['customRequest'], $guest['total']]);
        $awaiting = collect($engine->awaitingPayment($this->actor('cashierA'), ['id' => $this->outletId()]))->firstWhere('id', $id);
        $this->assertSame('pending', $awaiting['customRequest']['status'] ?? null);

        // Pembayaran ditolak sampai harga diisi; barista (absen saja) tidak boleh mengisi; input tidak sah ditolak.
        $this->expectAccess('conflict', fn () => $workflow->confirmQris($this->actor('cashierA'), ['orderId' => $id], $this->audit()), '/Isi dulu harga permintaan/');
        $this->expectAccess('forbidden', fn () => $this->price('baristaA', $id, ['action' => 'price', 'price' => 15_000]));
        $this->expectAccess('invalid', fn () => $this->price('cashierA', $id, ['action' => 'price', 'price' => -1]), '/tidak boleh negatif/');
        $this->expectAccess('invalid', fn () => $this->price('cashierA', $id, ['action' => 'price', 'price' => 'lima ribu']));
        $this->expectAccess('invalid', fn () => $this->price('cashierA', $id, ['action' => 'gratis']));
        $this->assertSame('pending', DB::table('orders')->where('id', $id)->value('custom_request_status'), 'penolakan tidak mengubah data');

        // Batas bayar didekatkan dulu, agar terlihat dihitung ulang dari saat harga diisi.
        DB::update("update orders set expires_at = now() + interval '1 minute' where id = ?", [$id]);
        $this->assertSame(['status' => 'priced', 'price' => 15_000, 'total' => 35_000], $this->price('cashierA', $id, ['action' => 'price', 'price' => '15000']));
        $row = DB::table('orders')->where('id', $id)->first();
        $this->assertSame([35_000, 20_000, 'priced', 15_000, static::$fixture['cashierA']['id']], [(int) $row->total, (int) $row->subtotal, $row->custom_request_status, (int) $row->custom_request_price, $row->custom_request_priced_by]);
        $this->assertNotNull($row->custom_request_priced_at);
        $this->assertTrue((bool) DB::selectOne(
            "select o.expires_at >= now() + make_interval(mins => t.pending_payment_ttl_minutes) - interval '1 minute' as ok from orders o join outlets t on t.id = o.outlet_id where o.id = ?",
            [$id],
        )->ok, 'batas bayar dihitung ulang dari sekarang');
        $this->assertSame(35_000, (int) DB::table('payments')->where('order_id', $id)->where('status', 'pending')->value('amount'), 'tagihan mengikuti total final');
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'order.custom_request_priced', $id));

        // Koreksi sebelum lunas boleh; pelanggan melihat QRIS + total final.
        $this->assertSame(32_000, $this->price('cashierA', $id, ['action' => 'price', 'price' => 12_000])['total']);
        $guest = $engine->guestOrder($id, $token);
        $this->assertNotNull($guest['qrisImageUrl'], 'QRIS tampil setelah total final');
        $this->assertSame([32_000, 'priced', 12_000], [$guest['total'], $guest['customRequest']['status'], $guest['customRequest']['price']]);

        $paid = $workflow->confirmQris($this->actor('cashierA'), ['orderId' => $id], $this->audit());
        $this->assertFalse($paid['alreadyPaid']);
        $payment = DB::table('payments')->where('order_id', $id)->first(['method', 'status', 'amount']);
        $this->assertSame(['qris', 'paid', 32_000], [$payment->method, $payment->status, (int) $payment->amount]);
        $audit = DB::table('audit_logs')->where('company_id', static::$fixture['A']['id'])->where('action', 'payment.confirmed')->where('entity_id', $id)->value('after');
        $this->assertSame(32_000, json_decode($audit, true)['amount']);
        // Setelah lunas harga terkunci.
        $this->expectAccess('conflict', fn () => $this->price('cashierA', $id, ['action' => 'price', 'price' => 1_000]), '/sebelum pesanan dibayar/');

        // Laporan: pendapatan request tampil sebagai baris sendiri; total pendapatan = total pesanan.
        $timezone = DB::table('outlets')->where('id', $this->outletId())->value('timezone');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $report = app(SalesReport::class)->report($this->actor('superA'), ['id' => $this->outletId(), 'timezone' => $timezone], $today, $today);
        $line = collect($report['categories'])->firstWhere('name', 'Permintaan di luar menu');
        $this->assertSame([1, 12_000], [$line['quantity'] ?? null, $line['revenue'] ?? null]);
        $this->assertSame(32_000, $report['summary']['revenue']);
    }

    public function test_bayar_saat_diantar_permintaan_tidak_tersedia_lalu_dibayar(): void
    {
        $engine = app(OrderEngine::class);
        $workflow = app(OrderWorkflow::class);
        $order = $this->order(['type' => 'dine_in', 'tableNumber' => 'L1-05', 'floor' => '1', 'area' => 'Keuangan', 'paymentMethod' => 'cash', 'customRequest' => 'Kopi tubruk'], 'qr_table');
        $id = $order['orderId'];
        $row = DB::table('orders')->where('id', $id)->first();
        $this->assertSame(['queued', 'pending', true, 'pending'], [$row->status, $row->payment_status, (bool) $row->pay_on_delivery, $row->custom_request_status]);

        $card = collect($engine->kdsBoard($this->actor('cashierA'), ['id' => $this->outletId()]))->firstWhere('id', $id);
        $this->assertSame(['text' => 'Kopi tubruk', 'status' => 'pending', 'price' => null], $card['customRequest'] ?? null);

        // Uang di meja belum bisa diterima sebelum harga request diisi.
        $this->expectAccess('conflict', fn () => $workflow->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => 50_000], $this->audit()), '/Isi dulu harga permintaan/');
        $this->assertSame(['status' => 'declined', 'price' => 0, 'total' => 20_000], $this->price('cashierA', $id, ['action' => 'decline']));
        $guest = $engine->guestOrder($id, $order['accessToken']);
        $this->assertSame(['declined', 0, 20_000], [$guest['customRequest']['status'], $guest['customRequest']['price'], $guest['total']]);

        $paid = $workflow->confirmCash($this->actor('cashierA'), ['orderId' => $id, 'tenderedAmount' => 20_000], $this->audit());
        $this->assertSame([false, 0], [$paid['alreadyPaid'], $paid['change']]);
        $payment = DB::table('payments')->where('order_id', $id)->first(['method', 'status', 'amount']);
        $this->assertSame(['cash', 'paid', 20_000], [$payment->method, $payment->status, (int) $payment->amount]);

        // Pesanan tanpa request: pengisian harga ditolak.
        $plain = $this->order();
        $this->expectAccess('invalid', fn () => $this->price('cashierA', $plain['orderId'], ['action' => 'price', 'price' => 5_000]), '/tidak punya permintaan/');
        $this->assertNull(DB::table('orders')->where('id', $plain['orderId'])->value('custom_request_status'));
    }
}
