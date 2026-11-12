<?php

namespace Tests\Feature;

use App\Services\Site\SiteContent;
use App\Support\PublicMeta;
use Tests\TestCase;

/**
 * Persiapan domain publik (12 November 2026): pratinjau tautan WhatsApp/Instagram dan mesin
 * pencari membaca HTML awal dari server; area staf tidak diindeks.
 */
class SeoTest extends TestCase
{
    public function test_halaman_depan_punya_meta_pratinjau_tautan_dari_server(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Sudaya Coffee">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.url('/images/program-petani-kopi-900.jpg').'">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringStartsWith('http', url(PublicMeta::IMAGE), 'gambar pratinjau wajib URL lengkap');
        $this->assertMatchesRegularExpression('/<meta name="description" content="Sudaya Coffee adalah kedai kopi yang tumbuh bersama petani kopi\.[^"]*">/', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertFileExists(public_path(PublicMeta::IMAGE));
    }

    public function test_area_staf_dan_status_pesanan_tidak_diindeks(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('og:title', $html);
        $this->assertNull(PublicMeta::for('order/status/abc'));
        $this->assertNull(PublicMeta::for('kiosk'));
        $this->assertNull(PublicMeta::for('admin'));
        $this->assertSame('Menu & pesan · Sudaya Coffee', PublicMeta::for('order')['title']);
    }

    public function test_robots_dan_sitemap_mengikuti_domain_aplikasi(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();

        foreach (['/admin', '/pos', '/kds', '/staff', '/login', '/order/status', '/kiosk'] as $path) {
            $this->assertStringContainsString("Disallow: {$path}\n", $robots);
        }
        $this->assertStringNotContainsString("Disallow: /order\n", $robots, 'menu pelanggan boleh diindeks');
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $robots);

        // Database tidak terjangkau di test ini: sitemap tetap memuat halaman depan.
        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $this->assertStringContainsString('<loc>'.url('/').'</loc>', $sitemap);
        $this->assertNotFalse(simplexml_load_string($sitemap));
    }

    public function test_deskripsi_dipotong_di_batas_kata(): void
    {
        $this->assertSame('Kopi enak.', PublicMeta::shorten("  Kopi \n enak.  "));
        $long = PublicMeta::shorten(SiteContent::DEFAULTS['about'].' '.SiteContent::DEFAULTS['about']);
        $this->assertLessThanOrEqual(160, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
        $this->assertDoesNotMatchRegularExpression('/\s…$/u', $long);
    }
}
