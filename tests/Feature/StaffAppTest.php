<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Manifest aplikasi staf hanya di area staf; assetlinks.json untuk APK. */
class StaffAppTest extends TestCase
{
    public function test_manifest_dipasang_di_login_bukan_di_halaman_pelanggan(): void
    {
        $login = $this->get('/login');
        $login->assertOk();
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $login->getContent());
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', $login->getContent());

        $home = $this->get('/');
        $this->assertStringNotContainsString('rel="manifest"', (string) $home->getContent());
    }

    public function test_assetlinks_404_sampai_dikonfigurasi(): void
    {
        config(['services.android.package' => null, 'services.android.sha256' => null]);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();

        $fingerprint = implode(':', array_fill(0, 32, '0F'));
        config(['services.android.package' => 'id.sudaya.staf', 'services.android.sha256' => $fingerprint]);
        $this->get('/.well-known/assetlinks.json')
            ->assertOk()
            ->assertExactJson([[
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => ['namespace' => 'android_app', 'package_name' => 'id.sudaya.staf', 'sha256_cert_fingerprints' => [$fingerprint]],
            ]]);
    }
}
