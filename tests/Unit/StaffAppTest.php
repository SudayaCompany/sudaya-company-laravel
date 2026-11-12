<?php

namespace Tests\Unit;

use App\Support\StaffApp;
use Tests\TestCase;

/** Aplikasi staf (PWA/APK): manifest, ikon, halaman offline, dan Digital Asset Links. */
class StaffAppTest extends TestCase
{
    public function test_pemasangan_hanya_ditawarkan_di_area_staf(): void
    {
        foreach (['/', '', 'order', 'order/status/abc', 'kiosk', 'kiosk/SDY01'] as $customer) {
            $this->assertFalse(StaffApp::offeredOn($customer), $customer);
        }
        foreach (['login', 'staff', 'staff/attendance', 'kds', 'pos/orders/1', 'admin', 'admin/outlets/x', 'set-password'] as $staff) {
            $this->assertTrue(StaffApp::offeredOn($staff), $staff);
        }
        // Awalan yang mirip bukan halaman pelanggan.
        $this->assertTrue(StaffApp::offeredOn('orders-archive'));
    }

    public function test_manifest_lengkap_dan_ikon_sesuai_ukuran(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['Sudaya Coffee', 'Sudaya', '/login', '/', 'standalone'], [
            $manifest['name'], $manifest['short_name'], $manifest['start_url'], $manifest['scope'], $manifest['display'],
        ]);
        $this->assertTrue(StaffApp::offeredOn(ltrim($manifest['start_url'], '/')));

        $purposes = [];
        foreach ($manifest['icons'] as $icon) {
            [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], "{$width}x{$height}", $icon['src']);
            $this->assertSame('image/png', $icon['type']);
            $purposes[] = "{$icon['purpose']}:{$icon['sizes']}";
        }
        // Syarat pasang Chrome: ikon 192 & 512; maskable untuk ikon adaptif Android.
        $this->assertEqualsCanonicalizing(['any:192x192', 'any:512x512', 'maskable:512x512'], $purposes);
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('icons/apple-touch-icon.png')), 0, 2));

        $this->assertSame(['/staff/attendance', '/kds', '/pos'], array_column($manifest['shortcuts'], 'url'));
        $this->assertSame(['Absen', 'Pesanan', 'Kasir'], array_column($manifest['shortcuts'], 'short_name'));
        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->assertTrue(StaffApp::offeredOn(ltrim($shortcut['url'], '/')), $shortcut['url']);
            $this->assertFileExists(public_path(ltrim($shortcut['icons'][0]['src'], '/')));
        }
    }

    public function test_service_worker_tidak_menyimpan_halaman_atau_data(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        // Hanya halaman offline yang disimpan; tidak ada cache.put / respons halaman dari cache.
        $this->assertStringContainsString('const PRECACHE = [OFFLINE_URL];', $sw);
        $this->assertStringNotContainsString('cache.put', $sw);
        $this->assertStringContainsString('request.method !== "GET" || request.mode !== "navigate"', $sw);
        $this->assertFileExists(public_path('offline.html'));
        $offline = (string) file_get_contents(public_path('offline.html'));
        $this->assertStringContainsString('Tidak ada koneksi internet', $offline);
        // Halaman offline mandiri: tidak memuat file lain yang tidak tersedia saat offline.
        $this->assertDoesNotMatchRegularExpression('/(src|href)="\/(?!\/)/', $offline);
    }

    public function test_asset_links_hanya_bila_paket_dan_sidik_jari_valid(): void
    {
        $fingerprint = implode(':', array_fill(0, 32, 'AB'));

        config(['services.android.package' => null, 'services.android.sha256' => null]);
        $this->assertNull(StaffApp::assetLinks());

        config(['services.android.package' => 'id.sudaya.staf', 'services.android.sha256' => 'bukan-sidik-jari']);
        $this->assertNull(StaffApp::assetLinks());

        config(['services.android.package' => 'bukan paket', 'services.android.sha256' => $fingerprint]);
        $this->assertNull(StaffApp::assetLinks());

        config(['services.android.package' => 'id.sudaya.staf', 'services.android.sha256' => strtolower($fingerprint).', '.$fingerprint]);
        $this->assertSame([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => 'id.sudaya.staf', 'sha256_cert_fingerprints' => [$fingerprint, $fingerprint]],
        ]], StaffApp::assetLinks());
    }
}
