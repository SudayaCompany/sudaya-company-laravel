<?php

namespace Tests\E2E;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Live\LiveContext;

/**
 * Alur transaksi tunai end-to-end lewat HTTP sungguhan (CSRF, sesi, flash):
 * POS/QR → bayar tunai → antrean → KDS → selesai → tutup kas.
 */
class PaymentFlowTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('e2epay');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Pay'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Pay'),
            'manager' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']], true, 'E2E Manager Pay'),
            'owner' => $ctx->createUser($A, [['role' => 'owner']], true, 'E2E Owner Pay'),
        ];
        $admin = $ctx->load($users['admin']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Iced Latte', 'categoryId' => $coffee, 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];

        return ['A' => $A, ...$users, 'latte' => $latte];
    }

    private function f(string $key): mixed
    {
        return static::$fixture[$key];
    }

    private function checkout(array $overrides = []): array
    {
        return [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'customerName' => 'Andi',
            'paymentMethod' => 'cash',
            'lines' => [['productId' => $this->f('latte'), 'modifierIds' => [], 'quantity' => 1]],
            ...$overrides,
        ];
    }

    /** POST lalu ambil flash dari halaman tujuan redirect. */
    private function act(Browser $browser, string $path, array $data = [], string $from = '/pos'): array
    {
        $browser->get($from);
        $response = $browser->post($path, $data);
        $this->assertSame(302, $response->getStatusCode(), "{$path}: ".$response->getStatusCode());

        return Browser::page($browser->get(Browser::location($response)))['flash'] ?? [];
    }

    private function props(Browser $browser, string $path): array
    {
        return Browser::page($browser->get($path))['props'];
    }

    public function test_alur_tunai_pos_qr_kds_sampai_tutup_kas(): void
    {
        $outletId = $this->f('A')['outletId'];
        $cashier = $this->login($this->f('cashier'));

        $pos = $this->props($cashier, '/pos');
        $this->assertSame([true, false], [$pos['canConfirmCash'], $pos['cashSessionOpen']]);

        // Tanpa sesi kas: order tersimpan, pembayaran ditolak dengan pesan jelas.
        $flash = $this->act($cashier, '/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout(), 'tenderedAmount' => 30_000]);
        $unpaidId = $flash['placed']['orderId'];
        $this->assertNull($flash['placed']['orderNumber']);
        $this->assertStringContainsString('Sesi kas belum dibuka', $flash['placed']['warning']);

        $flash = $this->act($cashier, '/pos/cash/open', ['outletId' => $outletId, 'openingAmount' => 100_000], '/pos/cash');
        $this->assertSame('Sesi kas dibuka.', $flash['success'] ?? null);
        $this->assertTrue($this->props($cashier, '/pos')['cashSessionOpen']);

        // POS: buat + bayar sekaligus.
        $flash = $this->act($cashier, '/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout(), 'tenderedAmount' => 30_000]);
        $posOrder = $flash['placed'];
        $this->assertMatchesRegularExpression('/^.+-\d{3}$/', $posOrder['orderNumber']);
        $this->assertSame([5_000, null], [$posOrder['change'], $posOrder['warning']]);

        // Order yang tadi belum dibayar dibayar dari halaman detail.
        $flash = $this->act($cashier, "/pos/orders/{$unpaidId}/pay", ['tenderedAmount' => 25_000], "/pos/orders/{$unpaidId}");
        $this->assertStringStartsWith('Pembayaran dikonfirmasi. Nomor antrean', $flash['success'] ?? '');
        $flash = $this->act($cashier, "/pos/orders/{$unpaidId}/pay", ['tenderedAmount' => 25_000], "/pos/orders/{$unpaidId}");
        $this->assertStringStartsWith('Sudah lunas sebelumnya', $flash['success'] ?? '');

        // QR meja: guest memesan, kasir menerima uang, guest melihat nomor antrean.
        $guest = $this->browser();
        $placed = json_decode((string) $guest->json('/order/checkout', ['outlet' => $this->f('A')['outletCode'], 'channel' => 'qr_table', 'checkout' => $this->checkout(['type' => 'dine_in', 'tableNumber' => 'T7'])])->getBody(), true);
        $this->ctx()->trackRateLimitKey('checkout:outlet:'.$this->f('A')['outletCode']);
        $qrId = $placed['orderId'];
        $this->assertSame('pending_payment', $this->props($guest, "/order/status/{$qrId}")['order']['status']);
        $this->act($cashier, "/pos/orders/{$qrId}/pay", ['tenderedAmount' => 50_000], "/pos/orders/{$qrId}");
        $status = $this->props($guest, "/order/status/{$qrId}")['order'];
        $this->assertSame(['queued', 'paid'], [$status['status'], $status['paymentStatus']]);
        $this->assertNotNull($status['orderNumber']);
        $this->assertStringStartsWith('order:', $status['topic']);

        // KDS barista: hanya order lunas, FIFO waktu bayar.
        $barista = $this->login($this->f('barista'));
        $kds = $this->props($barista, '/kds');
        $this->assertSame([true, false], [$kds['canUpdate'], $kds['canOverride']]);
        $this->assertSame([$posOrder['orderId'], $unpaidId, $qrId], array_column($kds['orders'], 'id'));
        $this->assertSame(['T7'], array_values(array_filter(array_column($kds['orders'], 'tableNumber'))));
        foreach ([['queued', 'preparing'], ['preparing', 'ready'], ['ready', 'completed']] as [$from, $to]) {
            $flash = $this->act($barista, "/kds/orders/{$qrId}/status", ['from' => $from, 'to' => $to], '/kds');
            $this->assertArrayNotHasKey('error', $flash, json_encode($flash));
            $this->assertSame($to, $this->props($guest, "/order/status/{$qrId}")['order']['status']);
        }
        // Status lama (double tap): ditolak dengan pesan, tidak maju dua langkah.
        $flash = $this->act($barista, "/kds/orders/{$qrId}/status", ['from' => 'ready', 'to' => 'completed'], '/kds');
        $this->assertStringContainsString('sudah berubah', $flash['error'] ?? '');

        // Struk lunas: data pembayaran tunai lengkap.
        $receipt = $this->props($cashier, "/pos/orders/{$posOrder['orderId']}/receipt")['order'];
        $payment = $receipt['payments'][0];
        $this->assertSame(['paid', 'cash', 25_000, 30_000, 5_000, 'E2E Kasir'], [$payment['status'], $payment['method'], $payment['amount'], $payment['tenderedAmount'], $payment['changeAmount'], $payment['confirmedByName']]);
        $this->assertSame($posOrder['orderNumber'], $receipt['orderNumber']);
        $this->assertNotNull($payment['paidAt']);

        // Daftar lunas dan tutup kas cocok: modal 100.000 + 3 × 25.000.
        $paidList = $this->props($cashier, '/pos/orders?view=paid')['list']['rows'];
        $this->assertEqualsCanonicalizing([$posOrder['orderId'], $unpaidId, $qrId], array_column($paidList, 'id'));
        $cash = $this->props($cashier, '/pos/cash');
        $this->assertSame(175_000, $cash['current']['totals']['expected']);
        $flash = $this->act($cashier, '/pos/cash/close', ['sessionId' => $cash['current']['id'], 'actualAmount' => 175_000], '/pos/cash');
        $this->assertSame('Sesi kas ditutup. Uang cocok.', $flash['success'] ?? null);
        $closed = $this->props($cashier, '/pos/cash');
        $this->assertNull($closed['current']);
        $this->assertSame([175_000, 175_000], [$closed['history'][0]['expected'], $closed['history'][0]['actual']]);
    }

    public function test_pembatalan_approval_dan_refund_lewat_http(): void
    {
        $outletId = $this->f('A')['outletId'];
        $cashier = $this->login($this->f('cashier'));
        if (! $this->props($cashier, '/pos')['cashSessionOpen']) {
            $this->act($cashier, '/pos/cash/open', ['outletId' => $outletId, 'openingAmount' => 50_000], '/pos/cash');
        }
        $order = $this->act($cashier, '/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout(), 'tenderedAmount' => 25_000])['placed'];

        $barista = $this->login($this->f('barista'));
        $flash = $this->act($barista, "/pos/orders/{$order['orderId']}/cancel", ['reason' => 'customer batal'], "/pos/orders/{$order['orderId']}");
        $this->assertSame('Pembatalan diajukan, menunggu persetujuan Manager.', $flash['success'] ?? null);

        $manager = $this->login($this->f('manager'));
        $detail = $this->props($manager, "/pos/orders/{$order['orderId']}");
        $this->assertTrue($detail['flags']['canDecide']);
        $approvalId = $detail['order']['pendingApproval']['id'];
        $flash = $this->act($manager, "/pos/approvals/{$approvalId}/decide", ['approve' => true], "/pos/orders/{$order['orderId']}");
        $this->assertSame('Pembatalan disetujui.', $flash['success'] ?? null);
        // Kasir tidak punya izin refund.
        $flash = $this->act($cashier, "/pos/orders/{$order['orderId']}/refund", [], "/pos/orders/{$order['orderId']}");
        $this->assertSame('Anda tidak memiliki izin untuk tindakan ini.', $flash['error'] ?? null);
        $flash = $this->act($manager, "/pos/orders/{$order['orderId']}/refund", [], "/pos/orders/{$order['orderId']}");
        $this->assertSame('Refund tunai Rp 25.000 tercatat.', $flash['success'] ?? null);
        $flash = $this->act($manager, "/pos/orders/{$order['orderId']}/refund", [], "/pos/orders/{$order['orderId']}");
        $this->assertSame('Refund pesanan ini sudah tercatat.', $flash['error'] ?? null);
        $this->assertSame(1, DB::table('refunds')->where('order_id', $order['orderId'])->count());
        $this->assertSame(['cancelled', 'refunded'], array_values((array) DB::table('orders')->where('id', $order['orderId'])->first(['status', 'payment_status'])));
    }

    public function test_hak_akses_kas_pembayaran_dan_kds(): void
    {
        $outletId = $this->f('A')['outletId'];
        $cashier = $this->login($this->f('cashier'));
        $order = $this->act($cashier, '/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout()])['placed'];

        $barista = $this->login($this->f('barista'));
        $this->assertSame('Anda tidak memiliki izin untuk tindakan ini.', $this->act($barista, "/pos/orders/{$order['orderId']}/pay", ['tenderedAmount' => 25_000])['error'] ?? null);
        $this->assertSame('Anda tidak memiliki izin untuk tindakan ini.', $this->act($barista, '/pos/cash/open', ['outletId' => $outletId, 'openingAmount' => 0])['error'] ?? null);
        $this->assertSame(403, $barista->get('/pos/cash')->getStatusCode());
        $this->assertSame(403, $cashier->get('/kds')->getStatusCode());
        $this->assertSame(403, $cashier->post("/kds/orders/{$order['orderId']}/status", ['from' => 'queued', 'to' => 'preparing'])->getStatusCode());

        // Owner melihat KDS tetapi tidak mengubah status; POS tetap 403.
        $owner = $this->login($this->f('owner'));
        $this->assertSame(200, $owner->get('/kds')->getStatusCode());
        $this->assertFalse($this->props($owner, '/kds')['canUpdate']);
        $this->assertSame(403, $owner->get('/pos/cash')->getStatusCode());

        // Tanpa CSRF ditolak sebelum aksi; order tetap belum dibayar.
        $this->assertSame(419, $cashier->post("/pos/orders/{$order['orderId']}/pay", ['tenderedAmount' => 25_000], withCsrf: false)->getStatusCode());
        $this->assertSame('pending_payment', DB::table('orders')->where('id', $order['orderId'])->value('status'));
        // Id rusak: pesan error, bukan 500.
        $this->assertSame('ID tidak valid.', $this->act($cashier, '/pos/orders/bukan-uuid/pay', ['tenderedAmount' => 1])['error'] ?? null);
    }
}
