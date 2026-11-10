<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Services\Orders\GuestTokens;
use App\Services\Orders\OrderEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Padanan tests/integration/orders.test.ts (bagian pembuatan order) dan
 * tests/integration/batch1-pos.test.ts. Pembayaran, nomor antrean, dan KDS ada di M4.
 */
class OrderEngineTest extends LiveTestCase
{
    use RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixture tanpa foto: storage tidak boleh tersentuh.
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
    }

    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('orda');
        $B = $ctx->createCompany('ordb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $coffee = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $size = $catalog->createModifierGroup($admin, $A['brandId'], [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Regular', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Large', 'priceDelta' => 6000]],
        ], $audit)['id'];
        $product = fn (string $name, int $price, array $groups = []) => $catalog->createProduct($admin, $A['brandId'], [
            'name' => $name, 'categoryId' => $coffee, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => $groups,
        ], null, $audit)['id'];
        $latte = $product('Iced Latte', 25000, [$size]);
        $tea = $product('Iced Tea', 15000);
        $soldOut = $product('Americano', 20000);
        $inactive = $product('Mocha', 30000);
        $catalog->setProductAvailability($admin, ['productId' => $soldOut, 'outletId' => $A['outletId'], 'isAvailable' => false], $audit);
        $catalog->setProductActive($admin, ['productId' => $inactive, 'isActive' => false], $audit);

        $menu = app(Storefront::class)->load($A['outletCode']);
        $modifiers = collect($menu['products'])->firstWhere('id', $latte)['groups'][0]['modifiers'];
        // Meja resmi untuk pesanan QR meja dine in (sejak daftar meja, EXT-026).
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'T03']);

        return [
            'A' => $A, 'B' => $B, ...$users,
            'latte' => $latte, 'tea' => $tea, 'soldOut' => $soldOut, 'inactive' => $inactive,
            'regular' => collect($modifiers)->firstWhere('name', 'Regular')['id'],
            'large' => collect($modifiers)->firstWhere('name', 'Large')['id'],
        ];
    }

    private function engine(): OrderEngine
    {
        return app(OrderEngine::class);
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    /** Input checkout minimal; harga tidak pernah dikirim (dan diabaikan bila ada). */
    private function checkout(array $overrides = []): array
    {
        return [
            'idempotencyKey' => (string) Str::uuid(),
            'type' => 'takeaway',
            'customerName' => 'Andi',
            'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['latte'], 'modifierIds' => [static::$fixture['large']], 'quantity' => 2]],
            ...$overrides,
        ];
    }

    private function delivery(array $overrides = []): array
    {
        return $this->checkout([
            'type' => 'delivery', 'customerName' => 'Budi', 'customerPhone' => '081234567890', 'deliveryAddress' => 'Jl. Merdeka No. 10',
            'lines' => [['productId' => static::$fixture['tea'], 'modifierIds' => [], 'quantity' => 1]],
            ...$overrides,
        ]);
    }

    private function guest(array $input, string $channel = 'qr_table'): array
    {
        return $this->engine()->create($channel, ['code' => static::$fixture['A']['outletCode']], null, $input, $this->audit());
    }

    private function pos(array $input, string $actor = 'cashierA'): array
    {
        return $this->engine()->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor($actor), $input, $this->audit());
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

    private function orderRow(string $id): object
    {
        return DB::table('orders')->where('id', $id)->first();
    }

    // --- membuat order (semua channel memakai engine yang sama) ------------------------

    public function test_guest_qr_harga_dihitung_server_dan_snapshot_tersimpan(): void
    {
        $f = static::$fixture;
        $created = $this->guest($this->checkout([
            'lines' => [
                // Field harga dari "browser" diabaikan.
                ['productId' => $f['latte'], 'modifierIds' => [$f['large']], 'quantity' => 2, 'unitPrice' => 1, 'lineTotal' => 2, 'notes' => 'Less ice'],
                ['productId' => $f['tea'], 'modifierIds' => [], 'quantity' => 1],
            ],
            'total' => 1,
            'subtotal' => 1,
            'status' => 'paid',
            'paymentStatus' => 'paid',
        ]));

        $this->assertFalse($created['replayed']);
        $this->assertSame((25000 + 6000) * 2 + 15000, $created['total']);
        $this->assertMatchesRegularExpression('/^[\w-]{40,}$/', $created['accessToken']);
        $this->assertSame(OrderEngine::reference($created['orderId']), $created['reference']);

        $row = $this->orderRow($created['orderId']);
        $this->assertSame(['qr_table', 'pending_payment', 'pending', 77000, 77000, null, null, $f['A']['id']], [
            $row->channel, $row->status, $row->payment_status, (int) $row->subtotal, (int) $row->total, $row->queue_number, $row->order_number, $row->company_id,
        ]);
        // Token asli tidak disimpan, hanya hash.
        $this->assertSame(GuestTokens::hash($created['accessToken']), $row->access_token_hash);
        // expires_at = waktu DB + TTL outlet.
        $ttl = (int) DB::table('outlets')->where('id', $f['A']['outletId'])->value('pending_payment_ttl_minutes');
        $minutes = (float) DB::selectOne('select extract(epoch from (expires_at - created_at)) / 60 as m from orders where id = ?', [$row->id])->m;
        $this->assertEqualsWithDelta($ttl, $minutes, 0.1);

        $items = DB::table('order_items')->where('order_id', $row->id)->get()->keyBy('product_id');
        $latte = $items[$f['latte']];
        $this->assertSame(['Iced Latte', 25000, 6000, 62000, 2, 'Less ice'], [$latte->product_name, (int) $latte->unit_price, (int) $latte->modifiers_total, (int) $latte->line_total, (int) $latte->quantity, $latte->notes]);
        $mods = DB::table('order_item_modifiers')->where('order_item_id', $latte->id)->get();
        $this->assertCount(1, $mods);
        $this->assertSame(['Size', 'Large', 6000], [$mods[0]->group_name, $mods[0]->modifier_name, (int) $mods[0]->price_delta]);

        $payments = DB::table('payments')->where('order_id', $row->id)->get();
        $this->assertCount(1, $payments);
        $this->assertSame(['cash', 'pending', 77000, null, null], [$payments[0]->method, $payments[0]->status, (int) $payments[0]->amount, $payments[0]->paid_at, $payments[0]->cash_session_id]);
        $this->assertSame(1, $this->ctx()->countAudit($f['A']['id'], 'order.created', $row->id));
        $log = DB::table('audit_logs')->where('entity_id', $row->id)->where('action', 'order.created')->first();
        $this->assertEquals(['channel' => 'qr_table', 'type' => 'takeaway', 'total' => 77000, 'deliveryFee' => 0, 'items' => 3, 'paymentMethod' => 'cash'], json_decode($log->after, true));
        $this->assertStringNotContainsString($created['accessToken'], (string) $log->after);
    }

    public function test_pos_barista_dan_kiosk_memakai_engine_yang_sama(): void
    {
        $f = static::$fixture;
        $pos = $this->pos($this->checkout(['customerName' => null]), 'baristaA');
        $kiosk = $this->guest($this->checkout(), 'kiosk');
        $web = $this->guest($this->checkout(), 'website');
        $counter = $this->guest($this->checkout(), 'qr_counter');

        $posRow = $this->orderRow($pos['orderId']);
        $this->assertSame(['pos', $f['baristaA']['id'], null], [$posRow->channel, $posRow->created_by, $posRow->access_token_hash]);
        $this->assertNull($pos['accessToken']);
        $this->assertSame(['kiosk', null], [$this->orderRow($kiosk['orderId'])->channel, $this->orderRow($kiosk['orderId'])->created_by]);
        $this->assertSame('website', $this->orderRow($web['orderId'])->channel);
        $this->assertSame('qr_counter', $this->orderRow($counter['orderId'])->channel);
        foreach ([$pos, $kiosk, $web, $counter] as $created) {
            $this->assertSame(['pending_payment', 'pending', null], [$this->orderRow($created['orderId'])->status, $this->orderRow($created['orderId'])->payment_status, $this->orderRow($created['orderId'])->queue_number]);
        }
    }

    public function test_channel_tidak_dikenal_ditolak(): void
    {
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(), 'admin'), '/Channel tidak valid/');
    }

    public function test_retry_dengan_key_sama_satu_order_dan_token_baru(): void
    {
        $input = $this->checkout();
        $first = $this->guest($input, 'website');
        $retry = $this->guest($input, 'website');

        $this->assertSame([$first['orderId'], true], [$retry['orderId'], $retry['replayed']]);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $input['idempotencyKey'])->count());
        $this->assertSame(1, DB::table('payments')->where('order_id', $first['orderId'])->count());
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'order.created', $first['orderId']));
        // Retry menerbitkan token baru; token lama tidak berlaku lagi.
        $this->assertNotNull($this->engine()->guestOrder($first['orderId'], $retry['accessToken']));
        $this->assertNull($this->engine()->guestOrder($first['orderId'], $first['accessToken']));
    }

    public function test_produk_dan_modifier_tidak_valid_ditolak(): void
    {
        $f = static::$fixture;
        $line = fn (string $product, array $mods = [], int $qty = 1) => ['lines' => [['productId' => $f[$product], 'modifierIds' => $mods, 'quantity' => $qty]]];

        $this->expectAccess('invalid', fn () => $this->guest($this->checkout($line('soldOut'))), '/^Americano: Maaf, produk ini baru saja tidak tersedia\.$/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout($line('inactive'))), '/tidak lagi tersedia/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout($line('latte'))), '/Pilih Size/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout($line('tea', [$f['large']]))), '/tidak valid/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout($line('latte', [$f['regular'], $f['large']]))));
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout($line('tea', [], 0))));
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['lines' => []])), '/kosong/');
        // Produk company lain tidak dikenali di menu outlet ini.
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['lines' => [['productId' => (string) Str::uuid(), 'modifierIds' => [], 'quantity' => 1]]])), '/tidak lagi tersedia/');
    }

    public function test_aturan_tipe_identitas_dan_metode_bayar(): void
    {
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['type' => 'delivery', 'customerPhone' => '081234567890'])), '/Alamat/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['type' => 'delivery', 'deliveryAddress' => 'Jl. Merdeka No. 10, Bandung'])), '/Nomor HP wajib/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['customerName' => ''])), '/Nama pemesan/');
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['customerPhone' => '12345'])), '/Nomor HP tidak valid/');
        // QRIS hanya bila outlet sudah mengunggah QRIS statisnya (dikonfirmasi kasir); di sini belum ada → ditolak.
        $this->expectAccess('invalid', fn () => $this->guest($this->checkout(['paymentMethod' => 'qris'])), '/^QRIS belum tersedia di outlet ini\. Silakan pilih tunai\.$/');

        $delivery = $this->guest($this->checkout(['type' => 'delivery', 'customerPhone' => '+62 812-3456-7890', 'deliveryAddress' => 'Jl. Merdeka No. 10, Bandung', 'tableNumber' => 'T9', 'locationNote' => 'teras']));
        $row = $this->orderRow($delivery['orderId']);
        $this->assertSame(['081234567890', null, null, 'delivery', 'Jl. Merdeka No. 10, Bandung'], [$row->customer_phone, $row->table_number, $row->location_note, $row->type, $row->delivery_address]);
        // Belum ada aturan ongkir resmi: total = harga item saja.
        $this->assertSame(31000 * 2, (int) $row->total);

        $dineIn = $this->guest($this->checkout(['type' => 'dine_in', 'tableNumber' => 't03', 'locationNote' => 'teras', 'deliveryAddress' => 'Jl. Merdeka No. 10']));
        $row = $this->orderRow($dineIn['orderId']);
        $this->assertSame(['T03', 'teras', null], [$row->table_number, $row->location_note, $row->delivery_address]);
    }

    public function test_channel_pos_wajib_staf_berizin_dan_outlet_company_sendiri(): void
    {
        $f = static::$fixture;
        $this->expectAccess('unauthenticated', fn () => $this->engine()->create('pos', ['id' => $f['A']['outletId']], null, $this->checkout(), $this->audit()));
        $this->expectAccess('not_found', fn () => $this->engine()->create('pos', ['id' => $f['A']['outletId']], $this->actor('superB'), $this->checkout(), $this->audit()));
        $this->expectAccess('not_found', fn () => $this->engine()->create('pos', ['code' => $f['B']['outletCode']], $this->actor('baristaA'), $this->checkout(), $this->audit()));
        // Owner hanya melihat laporan, tidak membuat order.
        $this->expectAccess('forbidden', fn () => $this->pos($this->checkout(), 'ownerA'));
        $this->assertSame(0, DB::table('orders')->where('company_id', $f['B']['id'])->count());
    }

    public function test_outlet_nonaktif_atau_tidak_dikenal_ditolak(): void
    {
        $f = static::$fixture;
        $this->expectAccess('invalid', fn () => $this->engine()->create('website', ['code' => 'TIDAKADA'], null, $this->checkout(), $this->audit()), '/Outlet tidak ditemukan/');

        DB::table('outlets')->where('id', $f['A']['outletId'])->update(['is_active' => false]);
        try {
            $this->expectAccess('invalid', fn () => $this->guest($this->checkout()), '/sedang tutup/');
            $this->expectAccess('invalid', fn () => $this->pos($this->checkout()), '/sedang tutup/');
        } finally {
            DB::table('outlets')->where('id', $f['A']['outletId'])->update(['is_active' => true]);
        }
    }

    // --- idempotensi (padanan batch1-pos.test.ts) --------------------------------------

    public function test_isi_berubah_pada_key_sama_ditolak_dan_data_tersimpan_tidak_berubah(): void
    {
        $input = $this->delivery();
        $first = $this->pos($input);
        $this->expectAccess('conflict', fn () => $this->pos([...$input, 'deliveryAddress' => 'Jl. Sudirman No. 99']), '/^'.preg_quote(OrderEngine::CONFLICT_MESSAGE, '/').'$/');
        $this->assertSame('Jl. Merdeka No. 10', $this->orderRow($first['orderId'])->delivery_address);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $input['idempotencyKey'])->count());

        // Key baru (dibuat client setelah konflik) menjadi pesanan terpisah.
        $fresh = $this->pos([...$input, 'idempotencyKey' => (string) Str::uuid(), 'deliveryAddress' => 'Jl. Sudirman No. 99']);
        $this->assertFalse($fresh['replayed']);
        $this->assertNotSame($first['orderId'], $fresh['orderId']);
    }

    public function test_konflik_di_channel_publik_tidak_memutar_token(): void
    {
        $input = $this->delivery();
        $first = $this->guest($input, 'website');
        $this->expectAccess('conflict', fn () => $this->guest([...$input, 'deliveryAddress' => 'Jl. Sudirman No. 99'], 'website'));
        $this->assertNotNull($this->engine()->guestOrder($first['orderId'], $first['accessToken']));
    }

    public function test_harga_berubah_di_antara_percobaan_tetap_retry(): void
    {
        $input = $this->delivery();
        $first = $this->pos($input);
        DB::table('orders')->where('id', $first['orderId'])->update(['total' => $first['total'] + 1000]);
        $retry = $this->pos($input);
        $this->assertSame([$first['orderId'], true, $first['total'] + 1000], [$retry['orderId'], $retry['replayed'], $retry['total']]);
    }

    public function test_order_lama_tanpa_request_hash_tetap_bisa_replay(): void
    {
        $input = $this->delivery();
        $first = $this->pos($input);
        DB::table('orders')->where('id', $first['orderId'])->update(['request_hash' => null]);
        $this->assertSame([$first['orderId'], true], array_values(array_intersect_key($this->pos($input), ['orderId' => 1, 'replayed' => 1])));
        $other = $this->pos([...$input, 'deliveryAddress' => 'Jl. Sudirman No. 99']);
        $this->assertSame([$first['orderId'], true], [$other['orderId'], $other['replayed']]);
        $row = $this->orderRow($first['orderId']);
        $this->assertSame(['Jl. Merdeka No. 10', null], [$row->delivery_address, $row->request_hash]);
    }

    public function test_permintaan_identik_bersamaan_hanya_satu_order_dan_satu_tagihan(): void
    {
        $f = static::$fixture;
        $input = $this->delivery();
        $job = ['op' => 'create', 'channel' => 'pos', 'outlet' => ['id' => $f['A']['outletId']], 'actorId' => $f['cashierA']['id'], 'input' => $input];
        $results = $this->concurrently([$job, $job, $job]);
        $created = array_column($results, 'result');

        $this->assertSame([true, true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertCount(1, array_unique(array_column($created, 'orderId')));
        $this->assertCount(1, array_filter($created, fn ($r) => ! $r['replayed']));
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $input['idempotencyKey'])->count());
        $this->assertSame(1, DB::table('payments')->where('order_id', $created[0]['orderId'])->count());
        $this->assertSame(1, DB::table('order_items')->where('order_id', $created[0]['orderId'])->count());
        $this->assertSame(1, $this->ctx()->countAudit($f['A']['id'], 'order.created', $created[0]['orderId']));
    }

    public function test_permintaan_bersamaan_isi_berbeda_satu_menang_sisanya_konflik(): void
    {
        $f = static::$fixture;
        $input = $this->delivery();
        $job = fn (array $checkout) => ['op' => 'create', 'channel' => 'website', 'outlet' => ['code' => $f['A']['outletCode']], 'input' => $checkout];
        $results = $this->concurrently([$job($input), $job([...$input, 'deliveryAddress' => 'Jl. Sudirman No. 99'])]);

        $won = array_values(array_filter($results, fn ($r) => $r['ok']));
        $lost = array_values(array_filter($results, fn ($r) => ! $r['ok']));
        $this->assertCount(1, $won, json_encode($results));
        $this->assertCount(1, $lost);
        $this->assertSame(['conflict', OrderEngine::CONFLICT_MESSAGE], [$lost[0]['reason'], $lost[0]['message']]);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $input['idempotencyKey'])->count());
    }

    // --- kedaluwarsa (pg_cron public.expire_pending_orders yang sudah ada) ---------------

    public function test_order_belum_dibayar_kedaluwarsa_lewat_fungsi_db(): void
    {
        $f = static::$fixture;
        $created = $this->guest($this->checkout());
        $notYet = $this->guest($this->checkout());
        // Belum lewat batas: tidak disentuh.
        $this->assertSame(0, (int) DB::selectOne('select public.expire_pending_orders(1, ?) as n', [$notYet['orderId']])->n);

        DB::table('orders')->where('id', $created['orderId'])->update(['expires_at' => DB::raw("now() - interval '1 minute'")]);
        // Fungsi yang sama dengan job pg_cron, dibatasi ke order ini (tidak menyentuh data lain).
        DB::selectOne('select public.expire_pending_orders(1, ?) as n', [$created['orderId']]);

        $row = $this->orderRow($created['orderId']);
        $this->assertSame(['cancelled', 'expired', 'Batas waktu pembayaran habis'], [$row->status, $row->payment_status, $row->cancel_reason]);
        $this->assertSame('expired', DB::table('payments')->where('order_id', $row->id)->value('status'));
        $this->assertSame(1, $this->ctx()->countAudit($f['A']['id'], 'order.expired', $row->id));
        $this->assertSame('pending_payment', $this->orderRow($notYet['orderId'])->status);

        $guest = $this->engine()->guestOrder($created['orderId'], $created['accessToken']);
        $this->assertSame(['cancelled', 'expired'], [$guest['status'], $guest['paymentStatus']]);
        // Retry key yang sama setelah kedaluwarsa tetap order lama (tidak menghidupkan lagi).
        $this->assertSame('cancelled', $this->orderRow($created['orderId'])->status);
    }

    public function test_job_expiry_tunggal_dari_pg_cron(): void
    {
        $jobs = DB::table('cron.job')->where('command', 'like', '%expire_pending_orders%')->get(['jobname', 'schedule', 'active']);
        $this->assertCount(1, $jobs, 'harus tepat satu job expiry');
        $this->assertSame(['sudaya-expire-pending-orders', '* * * * *', true], [$jobs[0]->jobname, $jobs[0]->schedule, (bool) $jobs[0]->active]);
    }

    // --- akses staf & guest -------------------------------------------------------------

    public function test_guest_hanya_melihat_order_miliknya_dengan_data_publik(): void
    {
        $created = $this->guest($this->checkout(['customerPhone' => '081234567890']));
        $other = $this->guest($this->checkout());

        $order = $this->engine()->guestOrder($created['orderId'], $created['accessToken']);
        $this->assertSame([$created['orderId'], 'pending_payment', 'Andi'], [$order['id'], $order['status'], $order['customerName']]);
        foreach (['customerPhone', 'accessTokenHash', 'access_token_hash', 'requestHash', 'createdBy', 'companyId', 'payments', 'history'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $order);
        }
        $this->assertNull($this->engine()->guestOrder($created['orderId'], $other['accessToken']));
        $this->assertNull($this->engine()->guestOrder($created['orderId'], null));
        $this->assertNull($this->engine()->guestOrder('bukan-uuid', $created['accessToken']));
        $this->assertNull($this->engine()->guestOrder((string) Str::uuid(), $created['accessToken']));
    }

    public function test_detail_dan_daftar_staf_terbatas_company_dan_izin(): void
    {
        $f = static::$fixture;
        $created = $this->pos($this->checkout(['customerName' => 'Detail Test', 'customerPhone' => '081234567890']), 'baristaA');

        $detail = $this->engine()->detail($this->actor('cashierA'), $created['orderId']);
        $this->assertSame([$created['orderId'], $f['A']['outletId'], '081234567890', $f['baristaA']['fullName']], [$detail['id'], $detail['outletId'], $detail['customerPhone'], $detail['createdByName']]);
        $this->assertCount(1, $detail['payments']);
        $this->assertSame(['cash', 'pending'], [$detail['payments'][0]['method'], $detail['payments'][0]['status']]);
        $this->assertSame(['order.created'], array_column($detail['history'], 'action'));
        $this->assertArrayNotHasKey('accessTokenHash', $detail);
        $this->assertNotNull($detail['expiresAt']);

        $this->expectAccess('not_found', fn () => $this->engine()->detail($this->actor('superB'), $created['orderId']));
        $this->expectAccess('invalid', fn () => $this->engine()->detail($this->actor('cashierA'), 'x'));

        $outlet = collect($this->engine()->outletsWithPermission($this->actor('cashierA'), 'order.view'))->firstWhere('id', $f['A']['outletId']);
        $pending = $this->engine()->list($this->actor('cashierA'), $outlet, ['status' => 'pending_payment', 'q' => null, 'page' => 1]);
        $this->assertContains($created['orderId'], array_column($pending['rows'], 'id'));
        $this->assertSame([], array_diff(array_unique(array_column($pending['rows'], 'status')), ['pending_payment']));
        $search = $this->engine()->list($this->actor('cashierA'), $outlet, ['status' => null, 'q' => $created['reference'], 'page' => 1]);
        $this->assertSame([$created['orderId']], array_column($search['rows'], 'id'));
        $byName = $this->engine()->list($this->actor('cashierA'), $outlet, ['status' => null, 'q' => 'detail test', 'page' => 1]);
        $this->assertSame([$created['orderId']], array_column($byName['rows'], 'id'));
        $active = $this->engine()->list($this->actor('cashierA'), $outlet, ['status' => 'active', 'q' => null, 'page' => 1]);
        $this->assertNotContains($created['orderId'], array_column($active['rows'], 'id'), 'order belum dibayar tidak masuk antrean produksi');

        $this->assertNotContains($f['A']['outletId'], array_column($this->engine()->outletsWithPermission($this->actor('superB'), 'order.view'), 'id'));
    }

    public function test_realtime_sinyal_order_baru_di_topik_outlet_tanpa_data(): void
    {
        $f = static::$fixture;
        $before = DB::selectOne("select coalesce(max(inserted_at), '-infinity') as at from realtime.messages where topic = ?", [OrderEngine::outletTopic($f['A']['outletId'])])->at;
        $this->guest($this->checkout());
        $messages = DB::table('realtime.messages')
            ->where('topic', OrderEngine::outletTopic($f['A']['outletId']))
            ->where('inserted_at', '>', $before)
            ->get(['event', 'payload', 'private']);

        $this->assertGreaterThanOrEqual(1, $messages->count());
        $this->assertSame('changed', $messages[0]->event);
        // realtime.send hanya menambahkan id pesan; tidak ada data order di payload.
        $this->assertSame(['id'], array_keys(json_decode($messages[0]->payload, true)));
        $this->assertFalse((bool) $messages[0]->private);
    }
}
