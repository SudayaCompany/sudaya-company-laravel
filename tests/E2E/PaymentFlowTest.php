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
        $size = $catalog->createModifierGroup($admin, $A['brandId'], [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Regular', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Large', 'priceDelta' => 6000]],
        ], $audit)['id'];
        $matcha = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Matcha', 'categoryId' => $coffee, 'basePrice' => '28000', 'isActive' => true, 'modifierGroupIds' => [$size]], null, $audit)['id'];
        $large = DB::table('modifiers')->where('group_id', $size)->where('name', 'Large')->value('id');
        // Produk jadi tertaut stok: stoknya dipotong otomatis saat dibayar.
        $stocked = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Roti Stok', 'categoryId' => $coffee, 'basePrice' => '20000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];
        $item = DB::table('inventory_items')->insertGetId([
            'company_id' => $A['id'], 'brand_id' => $A['brandId'], 'outlet_id' => $A['outletId'],
            'sku' => 'STK-E2E-'.$ctx->runId, 'name' => 'Roti Stok', 'kind' => 'finished_good', 'unit' => 'pcs', 'stock_qty' => 10,
        ]);
        DB::table('outlet_products')->updateOrInsert(['outlet_id' => $A['outletId'], 'product_id' => $stocked], ['inventory_item_id' => $item, 'is_available' => true]);

        // Meja resmi untuk pesanan QR meja dine in (sejak daftar meja, EXT-026).
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'T7']);

        return ['A' => $A, ...$users, 'latte' => $latte, 'matcha' => $matcha, 'large' => $large, 'stocked' => $stocked];
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

    /** Simpan (dan bayar) dari terminal POS lewat JSON, seperti `postJson` di browser. */
    private function pos(Browser $browser, array $data): array
    {
        $browser->get('/pos');
        $response = $browser->json('/pos/orders', $data);
        $this->assertContains($response->getStatusCode(), [200, 201], (string) $response->getBody());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['ok']);

        return $body;
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
        $flash = ['placed' => $this->pos($cashier, ['outletId' => $outletId, 'checkout' => $this->checkout(), 'tenderedAmount' => 30_000])];
        $unpaidId = $flash['placed']['orderId'];
        $this->assertNull($flash['placed']['orderNumber']);
        $this->assertStringContainsString('Sesi kas belum dibuka', $flash['placed']['warning']);

        $flash = $this->act($cashier, '/pos/cash/open', ['outletId' => $outletId, 'openingAmount' => 100_000], '/pos/cash');
        $this->assertSame('Sesi kas dibuka.', $flash['success'] ?? null);
        $this->assertTrue($this->props($cashier, '/pos')['cashSessionOpen']);

        // POS: buat + bayar sekaligus.
        $flash = ['placed' => $this->pos($cashier, ['outletId' => $outletId, 'checkout' => $this->checkout(), 'tenderedAmount' => 30_000])];
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
        $order = $this->pos($cashier, ['outletId' => $outletId, 'checkout' => $this->checkout(), 'tenderedAmount' => 25_000]);

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
        $order = $this->pos($cashier, ['outletId' => $outletId, 'checkout' => $this->checkout()]);

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
        // Terminal POS (JSON): error validasi dan izin sebagai JSON, tanpa CSRF 419.
        $empty = $cashier->json('/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout(['lines' => []])]);
        $this->assertSame([422, ['ok' => false, 'error' => 'Keranjang masih kosong.']], [$empty->getStatusCode(), json_decode((string) $empty->getBody(), true)]);
        $owner = $this->login($this->f('owner'));
        $this->assertSame(403, $owner->json('/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout()])->getStatusCode());
        $this->assertSame(419, $cashier->json('/pos/orders', ['outletId' => $outletId, 'checkout' => $this->checkout()], withCsrf: false)->getStatusCode());
    }

    public function test_kiosk_dengan_modifier_dibayar_kasir_lalu_masuk_kds(): void
    {
        $cashier = $this->login($this->f('cashier'));
        $this->ensureCashSession($cashier);

        // Guest di kiosk: harga modifier dihitung server, tidak ada cookie token di perangkat bersama.
        $kiosk = $this->browser();
        $response = $kiosk->json('/order/checkout', ['outlet' => $this->f('A')['outletCode'], 'channel' => 'kiosk', 'checkout' => $this->checkout([
            'type' => 'dine_in',
            'lines' => [['productId' => $this->f('matcha'), 'modifierIds' => [$this->f('large')], 'quantity' => 2, 'unitPrice' => 1]],
        ])]);
        $this->ctx()->trackRateLimitKey('checkout:outlet:'.$this->f('A')['outletCode']);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $placed = json_decode((string) $response->getBody(), true);
        $this->assertSame((28_000 + 6_000) * 2, $placed['total']);
        $this->assertStringNotContainsString('sdy_order_', implode(';', $response->getHeader('Set-Cookie')));

        // Kasir mencari kode referensi kiosk di daftar menunggu bayar lalu menerima uang.
        $pending = $this->props($cashier, '/pos/orders?view=pending&q='.$placed['reference'])['list']['rows'];
        $this->assertSame([$placed['orderId']], array_column($pending, 'id'));
        $this->assertSame('kiosk', $pending[0]['channel']);
        $flash = $this->act($cashier, "/pos/orders/{$placed['orderId']}/pay", ['tenderedAmount' => 70_000], "/pos/orders/{$placed['orderId']}");
        $this->assertStringContainsString('kembalian Rp 2.000', $flash['success'] ?? '');

        $barista = $this->login($this->f('barista'));
        $card = collect($this->props($barista, '/kds')['orders'])->firstWhere('id', $placed['orderId']);
        $this->assertSame('queued', $card['status']);
        $this->assertSame([['Matcha', 2, ['Large']]], array_map(fn ($l) => [$l['productName'], $l['quantity'], array_column($l['modifiers'], 'name')], $card['lines']));
    }

    public function test_produk_tertaut_stok_terjual_dan_stok_berkurang(): void
    {
        $outletId = $this->f('A')['outletId'];
        $cashier = $this->login($this->f('cashier'));
        $this->ensureCashSession($cashier);
        $item = DB::table('outlet_products')->where('outlet_id', $outletId)->where('product_id', $this->f('stocked'))->value('inventory_item_id');
        $stock = fn () => (string) DB::table('inventory_items')->where('id', $item)->value('stock_qty');
        $before = $stock();

        // Buat + bayar dari POS: lunas, nomor antrean terbit, stok produk jadi terpotong.
        $placed = $this->pos($cashier, [
            'outletId' => $outletId,
            'checkout' => $this->checkout(['lines' => [['productId' => $this->f('stocked'), 'modifierIds' => [], 'quantity' => 1]]]),
            'tenderedAmount' => 20_000,
        ]);
        $this->assertMatchesRegularExpression('/^.+-\d{3}$/', $placed['orderNumber']);
        $this->assertNull($placed['warning']);
        $this->assertSame(number_format((float) $before - 1, 3, '.', ''), $stock());
        $this->assertSame(1, DB::table('inventory_transactions')->where('reference_id', $placed['orderId'])->where('type', 'sale')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('entity_id', $placed['orderId'])->where('action', 'inventory.sale_posted')->count());
    }

    private function ensureCashSession(Browser $cashier): void
    {
        if (! $this->props($cashier, '/pos')['cashSessionOpen']) {
            $this->act($cashier, '/pos/cash/open', ['outletId' => $this->f('A')['outletId'], 'openingAmount' => 100_000], '/pos/cash');
        }
    }
}
