<?php

namespace Tests\Unit;

use App\Services\Orders\OrderEngine;
use App\Services\Push\NewOrderPush;
use App\Services\Push\PushKeys;
use App\Services\Push\PushSubscriptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Web Push (17 November 2026): host tujuan, isi notifikasi, dan kunci VAPID. */
class PushTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function endpoints(): array
    {
        return [
            'chrome/android (FCM)' => ['https://fcm.googleapis.com/fcm/send/abc:def', true],
            'firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAA', true],
            'safari/iphone' => ['https://web.push.apple.com/QF4abc', true],
            'edge/windows' => ['https://wns2-sg2p.notify.windows.com/w/?token=abc', true],
            'http' => ['http://fcm.googleapis.com/fcm/send/abc', false],
            'host lain' => ['https://evil.example.com/push', false],
            'host tiruan berawalan' => ['https://fcm.googleapis.com.evil.example/push', false],
            'host tiruan berakhiran' => ['https://evilfcm.googleapis.com/push', false],
            'localhost' => ['https://localhost/push', false],
            'ip internal' => ['https://169.254.169.254/latest/meta-data', false],
            'dengan port' => ['https://fcm.googleapis.com:8443/fcm/send/abc', false],
            'dengan kredensial' => ['https://user:pass@fcm.googleapis.com/fcm/send/abc', false],
            'bukan url' => ['fcm.googleapis.com/fcm/send/abc', false],
        ];
    }

    /** Server hanya mengirim ke layanan push resmi browser, bukan ke alamat sembarang dari klien (SSRF). */
    #[DataProvider('endpoints')]
    public function test_hanya_layanan_push_resmi_yang_diterima(string $endpoint, bool $allowed): void
    {
        $this->assertSame($allowed, PushSubscriptions::allowedEndpoint($endpoint));
    }

    public function test_isi_notifikasi_sama_dengan_banner_alarm_kasir(): void
    {
        $desk = ['id' => '0f8d3c2a-1111-2222-3333-444455556666', 'outlet_id' => 'outlet-1', 'order_number' => 'SDY-007', 'customer_name' => 'Rina',
            'channel' => 'qr_table', 'type' => 'dine_in', 'status' => 'queued', 'location_note' => 'Lantai 2 · dekat jendela'];
        $this->assertSame([
            'title' => 'Pesanan baru SDY-007',
            'body' => 'Rina · Antar ke meja · Lantai 2 · dekat jendela',
            'url' => '/kds?outlet=outlet-1',
            'tag' => 'sudaya-pesanan-baru',
            'orderId' => $desk['id'],
        ], NewOrderPush::payload((object) $desk));

        // Ambil di counter, belum dibayar, tanpa nama dan tanpa nomor antrean: pakai kode referensi.
        $pickup = (object) [...$desk, 'order_number' => null, 'customer_name' => null, 'channel' => 'website', 'type' => 'takeaway', 'status' => 'pending_payment', 'location_note' => null];
        $payload = NewOrderPush::payload($pickup);
        $this->assertSame('Pesanan baru '.OrderEngine::reference($desk['id']), $payload['title']);
        $this->assertSame('Tanpa nama · Ambil di counter · menunggu bayar', $payload['body']);
    }

    public function test_kunci_vapid_dari_env_atau_dibuat_dengan_format_yang_benar(): void
    {
        config(['services.webpush.public_key' => 'pub', 'services.webpush.private_key' => 'priv']);
        $this->assertSame(['publicKey' => 'pub', 'privateKey' => 'priv'], (new PushKeys)->keys());

        $keys = PushKeys::generate();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{87}$/', $keys['publicKey'], 'titik publik 65 byte (base64url)');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $keys['privateKey'], 'kunci privat 32 byte (base64url)');
        $this->assertSame("\x04", base64_decode(strtr($keys['publicKey'], '-_', '+/').'=')[0], 'titik tak terkompresi');
        $this->assertNotSame($keys, PushKeys::generate());
    }

    public function test_subject_vapid_dari_env_alamat_https_atau_mailto(): void
    {
        config(['services.webpush.subject' => null, 'app.url' => 'https://sudaya.example/']);
        $this->assertSame('https://sudaya.example', (new PushKeys)->subject());
        config(['app.url' => 'http://localhost:8000']);
        $this->assertSame('mailto:admin@sudaya.invalid', (new PushKeys)->subject());
        config(['services.webpush.subject' => 'mailto:pengelola@sudaya.example']);
        $this->assertSame('mailto:pengelola@sudaya.example', (new PushKeys)->subject());
    }
}
