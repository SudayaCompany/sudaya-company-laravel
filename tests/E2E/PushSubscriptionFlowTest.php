<?php

namespace Tests\E2E;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Live\LiveContext;

/**
 * Notifikasi pesanan baru saat aplikasi kasir tertutup (Web Push, 17 November 2026), lewat HTTP:
 * kunci publik hanya untuk yang memproses pesanan, langganan disimpan/dihapus lewat JSON + CSRF,
 * alamat selain layanan push resmi ditolak, dan barista (absen saja) tidak bisa berlangganan.
 */
class PushSubscriptionFlowTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $A = $ctx->createCompany('e2epush');

        return [
            'A' => $A,
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir Push'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Push'),
        ];
    }

    /** @return array<string, mixed> */
    private function subscription(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)], 'contentEncoding' => 'aes128gcm'];
    }

    public function test_kasir_berlangganan_dan_berhenti_barista_ditolak(): void
    {
        $cashier = $this->login(static::$fixture['cashier']);
        $page = Browser::page($cashier->get('/kds'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{87}$/', $page['props']['push']['publicKey'] ?? '', 'kunci publik VAPID untuk kasir');

        $endpoint = 'https://fcm.googleapis.com/fcm/send/e2e-'.Str::uuid();
        $stored = $cashier->json('/push/subscriptions', $this->subscription($endpoint));
        $this->assertSame(200, $stored->getStatusCode());
        $this->assertTrue(json_decode((string) $stored->getBody(), true)['ok']);
        $this->assertSame([static::$fixture['cashier']['id']], DB::table('push_subscriptions')->where('endpoint', $endpoint)->pluck('user_id')->all());

        // Alamat selain layanan push resmi (SSRF) dan kunci rusak ditolak.
        $ssrf = $cashier->json('/push/subscriptions', $this->subscription('https://169.254.169.254/latest/meta-data'));
        $this->assertSame(422, $ssrf->getStatusCode());
        $this->assertStringContainsString('tidak didukung', (string) $ssrf->getBody());
        $broken = $cashier->json('/push/subscriptions', [...$this->subscription('https://fcm.googleapis.com/fcm/send/e2e-rusak'), 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
        $this->assertSame(422, $broken->getStatusCode());

        // Tanpa token CSRF ditolak.
        $this->assertSame(419, $cashier->json('/push/subscriptions', $this->subscription($endpoint), [], false)->getStatusCode());

        // Keluar dari aplikasi: langganan perangkat ini dihapus.
        $deleted = $cashier->json('/push/subscriptions/delete', ['endpoint' => $endpoint]);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertSame(0, DB::table('push_subscriptions')->where('endpoint', $endpoint)->count());

        // Barista (absen saja): tanpa kunci publik dan tidak bisa berlangganan.
        $barista = $this->login(static::$fixture['barista']);
        $this->assertNull(Browser::page($barista->get('/barista'))['props']['push'] ?? null);
        $this->assertSame(403, $barista->json('/push/subscriptions', $this->subscription('https://fcm.googleapis.com/fcm/send/e2e-barista'))->getStatusCode());
        $this->assertSame(0, DB::table('push_subscriptions')->where('user_id', static::$fixture['barista']['id'])->count());
    }
}
