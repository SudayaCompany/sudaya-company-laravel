<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Orders\OrderEngine;
use App\Services\Push\NewOrderPush;
use App\Services\Push\PushSubscriptions;
use App\Services\Push\PushTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifikasi pesanan baru saat aplikasi kasir tertutup (Web Push, permintaan owner 17 November 2026).
 * Hanya perangkat user yang boleh memproses pesanan di outlet itu yang dikirimi (owner hanya melihat,
 * barista absen saja, tenant lain tidak); pesanan input kasir sendiri tidak dikirim; langganan yang
 * sudah tidak berlaku (404/410) dihapus. Layanan push diganti tiruan (tidak ada request keluar).
 */
class PushNotificationTest extends LiveTestCase
{
    /** @var list<array{endpoints: list<string>, payload: array<string, mixed>}> */
    public static array $sent = [];

    /** @var list<string> */
    public static array $expire = [];

    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('pusha');
        $B = $ctx->createCompany('pushb');
        $users = [
            'superA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'cashierB' => $ctx->createUser($B, [['role' => 'cashier', 'outlet' => 'outlet']]),
        ];
        $admin = $ctx->load($users['superA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $category = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $latte = $catalog->createProduct($admin, $A['brandId'], [
            'name' => 'Latte Push', 'categoryId' => $category, 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $audit)['id'];
        DB::table('outlet_tables')->insert(['company_id' => $A['id'], 'outlet_id' => $A['outletId'], 'number' => 'L1-01']);
        app(CashSessions::class)->open($ctx->load($users['cashierA']['id']), ['outletId' => $A['outletId'], 'openingAmount' => 100_000], $audit);

        return ['A' => $A, 'B' => $B, ...$users, 'latte' => $latte];
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$sent = [];
        self::$expire = [];
        $this->app->instance(PushTransport::class, new class implements PushTransport
        {
            public function send(array $subscriptions, string $payload): array
            {
                $endpoints = array_map(fn ($subscription) => $subscription->endpoint, $subscriptions);
                PushNotificationTest::$sent[] = ['endpoints' => $endpoints, 'payload' => json_decode($payload, true)];

                return array_values(array_intersect(PushNotificationTest::$expire, $endpoints));
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

    private function endpoint(): string
    {
        return 'https://fcm.googleapis.com/fcm/send/qa-'.Str::uuid();
    }

    private function subscribe(string $who, string $endpoint): void
    {
        app(PushSubscriptions::class)->store($this->actor($who), [
            'endpoint' => $endpoint, 'p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22), 'contentEncoding' => 'aes128gcm',
        ], 'phpunit');
    }

    private function input(array $extra = []): array
    {
        return [
            'idempotencyKey' => (string) Str::uuid(), 'customerName' => 'Rina', 'department' => 'Fundraising', 'paymentMethod' => 'cash',
            'lines' => [['productId' => static::$fixture['latte'], 'modifierIds' => [], 'quantity' => 1]],
            ...$extra,
        ];
    }

    private function guest(array $extra): array
    {
        return app(OrderEngine::class)->create('qr_table', ['code' => static::$fixture['A']['outletCode']], null, $this->input($extra), $this->audit());
    }

    public function test_pesanan_pelanggan_baru_dikirim_hanya_ke_perangkat_kasir_yang_berhak(): void
    {
        [$cashier, $owner, $barista, $tenantB] = [$this->endpoint(), $this->endpoint(), $this->endpoint(), $this->endpoint()];
        $this->subscribe('cashierA', $cashier);
        $this->subscribe('ownerA', $owner);
        $this->subscribe('baristaA', $barista);
        $this->subscribe('cashierB', $tenantB);

        $desk = $this->guest(['type' => 'dine_in', 'tableNumber' => 'L1-01', 'floor' => '1', 'area' => 'Depan']);
        $this->assertSame(1, app(NewOrderPush::class)->send($desk['orderId']));
        $this->assertCount(1, self::$sent);
        $this->assertSame([$cashier], self::$sent[0]['endpoints'], 'owner (hanya melihat), barista (absen saja), dan tenant lain tidak dikirimi');
        $order = DB::table('orders')->where('id', $desk['orderId'])->first(['order_number', 'location_note']);
        $this->assertStringContainsString('Lantai 1', (string) $order->location_note);
        $this->assertSame([
            'title' => 'Pesanan baru '.$order->order_number,
            'body' => 'Rina · Antar ke meja · '.$order->location_note,
            'url' => '/kds?outlet='.static::$fixture['A']['outletId'],
            'tag' => 'sudaya-pesanan-baru',
            'orderId' => $desk['orderId'],
        ], self::$sent[0]['payload']);

        // Ambil di counter + tunai: menunggu bayar di kasir.
        $pickup = $this->guest(['type' => 'takeaway']);
        $this->assertSame(1, app(NewOrderPush::class)->send($pickup['orderId']));
        $this->assertStringEndsWith('Ambil di counter · menunggu bayar', self::$sent[1]['payload']['body']);

        // Pesanan input kasir sendiri tidak dikirim.
        $pos = app(OrderEngine::class)->create('pos', ['id' => static::$fixture['A']['outletId']], $this->actor('cashierA'), $this->input(['type' => 'takeaway']), $this->audit());
        $this->assertSame(0, app(NewOrderPush::class)->send($pos['orderId']));
        $this->assertCount(2, self::$sent);
    }

    public function test_perangkat_pindah_akun_endpoint_kedaluwarsa_dihapus_dan_alamat_liar_ditolak(): void
    {
        // HP yang sama dipakai akun lain: langganan pindah ke user yang sedang login.
        $endpoint = $this->endpoint();
        $this->subscribe('baristaA', $endpoint);
        $this->subscribe('cashierA', $endpoint);
        $this->assertSame([static::$fixture['cashierA']['id']], DB::table('push_subscriptions')->where('endpoint', $endpoint)->pluck('user_id')->all());

        // Layanan push menjawab 404/410: langganannya dihapus.
        self::$expire = [$endpoint];
        $order = $this->guest(['type' => 'takeaway']);
        app(NewOrderPush::class)->send($order['orderId']);
        $this->assertContains($endpoint, self::$sent[0]['endpoints']);
        $this->assertSame(0, DB::table('push_subscriptions')->where('endpoint', $endpoint)->count(), 'endpoint kedaluwarsa dihapus');

        // Keluar dari aplikasi: hanya langganan milik user itu yang dihapus.
        $other = $this->endpoint();
        $this->subscribe('cashierA', $other);
        app(PushSubscriptions::class)->delete($this->actor('baristaA'), $other);
        $this->assertSame(1, DB::table('push_subscriptions')->where('endpoint', $other)->count(), 'user lain tidak bisa menghapus');
        app(PushSubscriptions::class)->delete($this->actor('cashierA'), $other);
        $this->assertSame(0, DB::table('push_subscriptions')->where('endpoint', $other)->count());

        try {
            app(PushSubscriptions::class)->store($this->actor('cashierA'), [
                'endpoint' => 'https://169.254.169.254/latest/meta-data', 'p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22), 'contentEncoding' => 'aes128gcm',
            ], null);
            $this->fail('alamat selain layanan push resmi harus ditolak');
        } catch (AccessException $error) {
            $this->assertSame('invalid', $error->reason);
        }
        $this->assertSame(0, DB::table('push_subscriptions')->where('endpoint', 'like', 'https://169.254.%')->count());
    }
}
