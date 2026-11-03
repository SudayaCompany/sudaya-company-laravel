<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

/** Tanpa database: guest, header keamanan, halaman error, dan log. */
class HttpHardeningTest extends TestCase
{
    public function test_guest_ke_area_internal_diarahkan_ke_login_dengan_next(): void
    {
        $this->get('/admin')->assertRedirect('/login?next=%2Fadmin');
        foreach (['/staff', '/kds', '/pos', '/admin/users', '/auth/set-password'] as $path) {
            $this->get($path)->assertRedirectContains('/login?next=');
        }
    }

    public function test_mutasi_admin_tanpa_sesi_ditolak_sebelum_menyentuh_data(): void
    {
        $this->post('/admin/users/set-active', ['userId' => '6f0c2b7e-0000-4000-8000-000000000001', 'isActive' => false])
            ->assertRedirect('/login');
        $this->postJson('/admin/roles/set-permission', [])->assertUnauthorized();
    }

    public function test_header_keamanan_di_semua_respons(): void
    {
        foreach ([$this->get('/'), $this->get('/login'), $this->getJson('/api/does-not-exist'), $this->get('/tidak-ada')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString('camera=(self), geolocation=(self)', $response->headers->get('Permissions-Policy'));
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{32}'/", $csp);
            $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        }
    }

    public function test_script_halaman_membawa_nonce_yang_sama_dengan_csp(): void
    {
        $response = $this->get('/login');
        preg_match("/'nonce-([A-Za-z0-9]{32})'/", $response->headers->get('Content-Security-Policy'), $nonce);
        $this->assertNotEmpty($nonce);
        preg_match_all('/<script\b[^>]*>/', $response->getContent(), $scripts);
        foreach ($scripts[0] as $tag) {
            // Data halaman Inertia berupa JSON, bukan script yang dijalankan.
            if (str_contains($tag, 'application/json')) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$nonce[1].'"', $tag);
        }
    }

    public function test_hsts_hanya_di_production(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
        $this->app['env'] = 'production';
        $this->get('/')->assertHeader('Strict-Transport-Security', 'max-age=63072000');
    }

    public function test_404_halaman_inertia_dengan_status_asli(): void
    {
        $this->get('/tidak-ada')->assertNotFound()->assertInertia(fn ($page) => $page->component('errors/not-found', shouldExist: true));
    }

    public function test_500_memakai_view_statis_tanpa_detail(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new \RuntimeException('detail internal'));
        $response = $this->get('/_test/boom')->assertStatus(500);
        $this->assertStringContainsString('Terjadi kesalahan', $response->getContent());
        $this->assertStringNotContainsString('detail internal', $response->getContent());
    }

    public function test_query_exception_dicatat_tanpa_nilai_parameter(): void
    {
        config(['app.debug' => false]);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });
        Route::get('/_test/query', fn () => throw new QueryException(
            'pgsql',
            'insert into "customers" ("name", "phone") values (?, ?)',
            ['Budi Santoso', '081234567890'],
            new PDOException('SQLSTATE[23505]: duplicate key value'),
        ));

        $this->get('/_test/query')->assertStatus(500);

        $line = implode("\n", $logged);
        $this->assertStringContainsString('query_failed', $line);
        $this->assertStringContainsString('insert into', $line);
        $this->assertStringNotContainsString('Budi', $line);
        $this->assertStringNotContainsString('0812', $line);
    }
}
