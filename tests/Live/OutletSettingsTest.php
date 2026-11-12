<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use App\Services\Outlets\OutletSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pengaturan outlet dan daftar meja resmi (sebelum UAT, disetujui owner 4 November 2026). */
class OutletSettingsTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('outa');
        $B = $ctx->createCompany('outb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $cat = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $tea = $catalog->createProduct($admin, $A['brandId'], ['name' => 'Iced Tea', 'categoryId' => $cat, 'basePrice' => '15000', 'isActive' => true, 'modifierGroupIds' => []], null, $audit)['id'];

        return ['A' => $A, 'B' => $B, ...$users, 'tea' => $tea];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function settings(): OutletSettings
    {
        return app(OutletSettings::class);
    }

    private function outletId(): string
    {
        return static::$fixture['A']['outletId'];
    }

    private function values(array $overrides = []): array
    {
        $o = DB::table('outlets')->where('id', $this->outletId())->first();

        return ['name' => $o->name, 'address' => $o->address, 'orderNumberPrefix' => $o->order_number_prefix, 'pendingPaymentTtlMinutes' => (int) $o->pending_payment_ttl_minutes, 'isActive' => (bool) $o->is_active, ...$overrides];
    }

    private function checkout(string $channel, array $overrides = []): array
    {
        return app(OrderEngine::class)->create($channel, ['code' => static::$fixture['A']['outletCode']], null, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'customerName' => 'Tamu', 'department' => 'Fundraising', 'floor' => '1', 'area' => 'Depan', 'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['tea'], 'modifierIds' => [], 'quantity' => 1]],
            ...$overrides,
        ], $this->audit());
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

    public function test_ubah_pengaturan_tercatat_audit_dan_dipakai_order_engine(): void
    {
        $A = static::$fixture['A'];
        $result = $this->settings()->update($this->actor('adminA'), $this->outletId(), $this->values([
            'name' => 'Sudaya Uji Kemang', 'address' => 'Jl. Uji 1', 'orderNumberPrefix' => 'kmg', 'pendingPaymentTtlMinutes' => 25,
        ]), $this->audit());
        $this->assertEqualsCanonicalizing(['name', 'address', 'orderNumberPrefix', 'pendingPaymentTtlMinutes'], $result['changed']);
        $log = DB::table('audit_logs')->where('entity_id', $this->outletId())->where('action', 'outlet.updated')->first();
        $this->assertSame(['Sudaya Uji Kemang', 'KMG', 25], [json_decode($log->after, true)['name'], json_decode($log->after, true)['orderNumberPrefix'], json_decode($log->after, true)['pendingPaymentTtlMinutes']]);
        $this->assertArrayNotHasKey('isActive', json_decode($log->after, true), 'hanya field yang berubah');

        // Tanpa perubahan: tidak ada audit baru.
        $this->assertSame(['changed' => []], $this->settings()->update($this->actor('adminA'), $this->outletId(), $this->values(), $this->audit()));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'outlet.updated', $this->outletId()));

        // Nama baru langsung di menu customer (cache dibuang).
        $this->assertSame('Sudaya Uji Kemang', app(Storefront::class)->cached($A['outletCode'])['outlet']['name']);

        // TTL baru untuk order berikutnya; prefix baru untuk nomor antrean berikutnya.
        $order = $this->checkout('website', ['type' => 'takeaway']);
        $minutes = (float) DB::selectOne('select extract(epoch from (expires_at - created_at)) / 60 as m from orders where id = ?', [$order['orderId']])->m;
        $this->assertEqualsWithDelta(25, $minutes, 0.1);
        app(CashSessions::class)->open($this->actor('cashierA'), ['outletId' => $this->outletId(), 'openingAmount' => 0], $this->audit());
        $paid = app(OrderWorkflow::class)->confirmCash($this->actor('cashierA'), ['orderId' => $order['orderId'], 'tenderedAmount' => 15000], $this->audit());
        $this->assertMatchesRegularExpression('/^KMG-\d{3}$/', $paid['orderNumber']);
    }

    public function test_nonaktifkan_lalu_aktifkan_outlet(): void
    {
        $code = static::$fixture['A']['outletCode'];
        // Muat cache menu dulu agar terbukti cache ikut dibuang.
        $this->assertNotNull(app(Storefront::class)->cached($code));
        $this->settings()->update($this->actor('adminA'), $this->outletId(), $this->values(['isActive' => false]), $this->audit());
        $this->assertNull(app(Storefront::class)->cached($code), 'menu customer tertutup');
        $this->expectAccess('invalid', fn () => $this->checkout('website', ['type' => 'takeaway']), '/sedang tutup/');
        // Outlet nonaktif tetap terlihat di daftar pengaturan agar bisa diaktifkan lagi.
        $this->assertFalse(collect($this->settings()->list($this->actor('adminA')))->firstWhere('id', $this->outletId())['isActive']);

        $this->settings()->update($this->actor('adminA'), $this->outletId(), $this->values(['isActive' => true]), $this->audit());
        $this->assertNotNull(app(Storefront::class)->cached($code), 'menu customer dibuka lagi tanpa menunggu cache kedaluwarsa');
        $this->assertFalse($this->checkout('website', ['type' => 'takeaway'])['replayed']);
    }

    public function test_izin_dan_isolasi_company(): void
    {
        foreach (['managerA', 'cashierA'] as $name) {
            $this->expectAccess('forbidden', fn () => $this->settings()->update($this->actor($name), $this->outletId(), $this->values(['name' => 'Coba']), $this->audit()));
            $this->expectAccess('forbidden', fn () => $this->settings()->addTables($this->actor($name), $this->outletId(), 'X1', $this->audit()));
            $this->assertSame([], $this->settings()->list($this->actor($name)));
        }
        $this->expectAccess('not_found', fn () => $this->settings()->get($this->actor('superB'), $this->outletId()));
        $this->expectAccess('not_found', fn () => $this->settings()->update($this->actor('superB'), $this->outletId(), $this->values(['name' => 'Bajak']), $this->audit()));
        $this->assertNotContains($this->outletId(), array_column($this->settings()->list($this->actor('superB')), 'id'));
        $this->expectAccess('invalid', fn () => $this->settings()->update($this->actor('adminA'), $this->outletId(), $this->values(['orderNumberPrefix' => 'A-B']), $this->audit()), '/Prefix antrean/');
        $this->assertNotSame('Bajak', DB::table('outlets')->where('id', $this->outletId())->value('name'));
    }

    public function test_daftar_meja_tanpa_duplikat_dan_status(): void
    {
        $A = static::$fixture['A'];
        $first = $this->settings()->addTables($this->actor('adminA'), $this->outletId(), 'm1, m2 M3', $this->audit());
        $this->assertSame(['created' => ['M1', 'M2', 'M3'], 'existing' => []], $first);
        $again = $this->settings()->addTables($this->actor('adminA'), $this->outletId(), 'M2, M4', $this->audit());
        $this->assertSame(['created' => ['M4'], 'existing' => ['M2']], $again);
        $this->assertSame(1, DB::table('outlet_tables')->where('outlet_id', $this->outletId())->where('number', 'M2')->count());
        // Constraint database ikut menjaga, bukan hanya aplikasi.
        foreach (['M1', 'm1-bawah'] as $number) {
            try {
                DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $this->outletId(), 'number' => $number]);
                $this->fail("{$number} seharusnya ditolak database");
            } catch (QueryException $error) {
                // 23505 = duplikat (outlet_id, number); 23514 = format nomor (huruf kecil) ditolak check constraint.
                $this->assertSame($number === 'M1' ? '23505' : '23514', $error->getCode());
            }
        }

        $tableId = DB::table('outlet_tables')->where('outlet_id', $this->outletId())->where('number', 'M3')->value('id');
        $this->assertSame(['number' => 'M3', 'isActive' => false], $this->settings()->setTableActive($this->actor('adminA'), $tableId, false, $this->audit()));
        $this->assertFalse((bool) DB::table('outlet_tables')->where('id', $tableId)->value('is_active'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'outlet_table.status_changed', $tableId));
        $this->assertSame(2, $this->ctx()->countAudit($A['id'], 'outlet_table.created', $this->outletId()));
        $this->expectAccess('not_found', fn () => $this->settings()->setTableActive($this->actor('superB'), $tableId, true, $this->audit()));
        $this->expectAccess('forbidden', fn () => $this->settings()->setTableActive($this->actor('managerA'), $tableId, true, $this->audit()));
    }

    public function test_checkout_qr_meja_hanya_untuk_meja_aktif_terdaftar(): void
    {
        $this->settings()->addTables($this->actor('adminA'), $this->outletId(), 'Q1, Q2', $this->audit());
        $q2 = DB::table('outlet_tables')->where('outlet_id', $this->outletId())->where('number', 'Q2')->value('id');
        $this->settings()->setTableActive($this->actor('adminA'), $q2, false, $this->audit());
        $before = DB::table('orders')->where('outlet_id', $this->outletId())->count();

        $ok = $this->checkout('qr_table', ['tableNumber' => 'q1']);
        $this->assertSame('Q1', DB::table('orders')->where('id', $ok['orderId'])->value('table_number'));
        $this->expectAccess('invalid', fn () => $this->checkout('qr_table', ['tableNumber' => 'Q9']), '/^Meja Q9 tidak terdaftar atau sedang tidak aktif\./');
        $this->expectAccess('invalid', fn () => $this->checkout('qr_table', ['tableNumber' => 'Q2']), '/^Meja Q2 tidak terdaftar/');
        $this->expectAccess('invalid', fn () => $this->checkout('qr_table'), '/^Nomor meja wajib untuk pesanan dari QR meja\.$/');
        // Order yang ditolak tidak tersimpan (rollback penuh).
        $this->assertSame($before + 1, DB::table('orders')->where('outlet_id', $this->outletId())->count());

        // Retry order lama tetap dikembalikan walau mejanya kemudian dinonaktifkan.
        $retryInput = ['idempotencyKey' => (string) Str::uuid(), 'tableNumber' => 'Q1'];
        $original = $this->checkout('qr_table', $retryInput);
        $q1 = DB::table('outlet_tables')->where('outlet_id', $this->outletId())->where('number', 'Q1')->value('id');
        $this->settings()->setTableActive($this->actor('adminA'), $q1, false, $this->audit());
        $replay = $this->checkout('qr_table', $retryInput);
        $this->assertSame([$original['orderId'], true], [$replay['orderId'], $replay['replayed']]);

        // Kompatibilitas: takeaway dari QR meja, POS, kiosk, dan web tidak memakai daftar meja.
        $this->assertFalse($this->checkout('qr_table', ['type' => 'takeaway'])['replayed']);
        $this->assertFalse($this->checkout('kiosk', ['tableNumber' => 'BEBAS1'])['replayed']);
        $this->assertFalse($this->checkout('website', ['tableNumber' => 'BEBAS2'])['replayed']);
        $pos = app(OrderEngine::class)->create('pos', ['id' => $this->outletId()], $this->actor('cashierA'), [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'dine_in', 'tableNumber' => 'BEBAS3', 'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['tea'], 'modifierIds' => [], 'quantity' => 1]],
        ], $this->audit());
        $this->assertSame('BEBAS3', DB::table('orders')->where('id', $pos['orderId'])->value('table_number'));
    }

    public function test_schema_meja_rls_aktif_tanpa_policy(): void
    {
        $tables = DB::table('pg_tables')->where('schemaname', 'public')->whereIn('tablename', ['outlet_tables', 'migrations'])->pluck('rowsecurity', 'tablename')->all();
        $this->assertEquals(['outlet_tables' => true, 'migrations' => true], $tables);
        $this->assertSame(0, DB::table('pg_policies')->where('schemaname', 'public')->whereIn('tablename', ['outlet_tables', 'migrations'])->count());
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_tables where schemaname = 'public' and not rowsecurity")->n);
    }
}
