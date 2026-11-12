<?php

namespace App\Http\Controllers;

use App\Services\Seed\InitialData;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * robots.txt dan sitemap.xml mengikuti domain aplikasi (APP_URL/host permintaan), jadi tidak
 * perlu diubah saat domain production dipasang. Hanya halaman publik yang ditawarkan ke mesin
 * pencari; area staf, kiosk, dan status pesanan pelanggan tidak.
 */
class SeoController
{
    private const PRIVATE_PATHS = ['/admin', '/pos', '/kds', '/staff', '/login', '/auth', '/api', '/order/status', '/kiosk'];

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Allow: /'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = "Disallow: {$path}";
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(): Response
    {
        $urls = [url('/')];
        try {
            $codes = DB::table('outlets')->join('companies', 'companies.id', '=', 'outlets.company_id')
                ->where('companies.code', InitialData::TARGET['company']['code'])
                ->where('outlets.is_active', true)->orderBy('outlets.code')->pluck('outlets.code');
            foreach ($codes as $code) {
                $urls[] = url('/order').'?outlet='.rawurlencode((string) $code);
            }
        } catch (Throwable) {
            // Database tidak terjangkau: halaman depan saja.
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
