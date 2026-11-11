<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Halaman depan publik tanpa login (11 November 2026): konten situs tetap tampil dengan teks
 * bawaan walau database tidak terjangkau; pengaturannya hanya untuk staf yang login.
 */
class PublicSiteTest extends TestCase
{
    public function test_halaman_depan_publik_memuat_konten_bawaan_tanpa_kontak_fiktif(): void
    {
        $first = $this->get('/')->assertOk();
        $first->assertInertia(fn (AssertableInertia $page) => $page->component('home')->missing('site')->missing('cafe'));

        // Partial reload seperti yang dilakukan <Deferred> di browser.
        $site = $this->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $first->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'home',
            'X-Inertia-Partial-Data' => 'site',
        ])->assertOk()->json('props.site');

        $this->assertSame('https://sahabatberbagi.org', $site['donationUrl']);
        $this->assertSame(['Pelatihan Kopi', 'Pemberdayaan Petani Kopi'], array_column($site['programs'], 'title'));
        $this->assertSame([], $site['trainings']);
        $this->assertSame(['whatsapp' => null, 'instagram' => null, 'email' => null], $site['links'], 'kontak kosong sampai diisi owner');
    }

    public function test_pengaturan_konten_situs_wajib_login(): void
    {
        $this->get('/admin/site')->assertRedirect('/login?next=%2Fadmin%2Fsite');
        $this->post('/admin/site', ['about' => 'x'])->assertRedirectContains('/login');
    }
}
