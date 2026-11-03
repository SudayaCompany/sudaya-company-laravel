<?php

namespace Tests\E2E;

use App\Services\Auth\SupabaseAuth;
use App\Services\Auth\SupabaseAuthException;
use Illuminate\Support\Facades\DB;
use Tests\Live\LiveContext;

/** Padanan tests/e2e/routes.test.ts, plus CSRF, cookie, refresh, undangan, dan reset password. */
class RoutesTest extends ServerTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $company = $ctx->createCompany('e2e');

        return [
            'company' => $company,
            'superAdmin' => $ctx->createUser($company, [['role' => 'super_admin']], true, 'E2E Super Admin'),
            'barista' => $ctx->createUser($company, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista'),
            'cashier' => $ctx->createUser($company, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir'),
        ];
    }

    private function f(string $key): array
    {
        return static::$fixture[$key];
    }

    // --- guest -------------------------------------------------------------------

    public function test_guest_halaman_publik_dan_area_internal(): void
    {
        $browser = $this->browser();
        $order = $browser->get('/order');
        $this->assertSame(200, $order->getStatusCode());
        $this->assertDoesNotMatchRegularExpression('#href="/(admin|staff|kds)#', (string) $order->getBody());

        $admin = $browser->get('/admin');
        $this->assertSame(302, $admin->getStatusCode());
        $this->assertSame('/login?next=%2Fadmin', Browser::location($admin));
        foreach (['/staff', '/kds', '/pos'] as $path) {
            $this->assertSame(302, $browser->get($path)->getStatusCode(), $path);
        }
    }

    public function test_post_tanpa_token_csrf_ditolak_419(): void
    {
        $browser = $this->browser();
        $response = $browser->post('/login', ['email' => $this->f('barista')['email'], 'password' => $this->f('barista')['password']], false);
        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(0, $this->ctx()->countAudit($this->f('company')['id'], 'auth.login', $this->f('barista')['id']));
    }

    public function test_mutasi_admin_tanpa_sesi_ditolak(): void
    {
        $response = $this->browser()->post('/admin/users/set-active', ['userId' => $this->f('barista')['id'], 'isActive' => false]);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', Browser::location($response));
        $this->assertTrue((bool) DB::table('users')->where('id', $this->f('barista')['id'])->value('is_active'));
    }

    // --- login -------------------------------------------------------------------

    public function test_password_salah_pesan_error_tanpa_sesi(): void
    {
        $browser = $this->browser();
        $response = $browser->post('/login', ['email' => $this->f('barista')['email'], 'password' => 'salah-total-123']);
        $this->assertSame(302, $response->getStatusCode());

        $page = Browser::page($browser->get('/login'));
        $this->assertSame('Email atau password salah.', $page['props']['errors']['form']);
        $this->assertSame(302, $browser->get('/staff')->getStatusCode());
    }

    public function test_login_valid_sesi_baru_redirect_sesuai_role_dan_audit(): void
    {
        $browser = $this->browser();
        $browser->get('/login');
        $before = $browser->cookie('laravel-session') ?? $browser->cookie('sudaya-coffee-session');
        $response = $browser->post('/login', ['email' => $this->f('barista')['email'], 'password' => $this->f('barista')['password']]);

        $this->assertSame('/staff', Browser::location($response));
        $sessionCookie = null;
        foreach ($browser->jar as $cookie) {
            if (str_ends_with($cookie->getName(), '-session')) {
                $sessionCookie = $cookie;
            }
        }
        $this->assertNotNull($sessionCookie);
        // Id sesi diganti saat login (anti session fixation) dan cookie tidak bisa dibaca JavaScript.
        $this->assertNotSame($before, $sessionCookie->getValue());
        $this->assertTrue($sessionCookie->getHttpOnly());
        $setCookie = implode("\n", $response->getHeader('Set-Cookie'));
        $this->assertMatchesRegularExpression('/-session=[^;]+;.*samesite=lax/i', $setCookie);
        // Cookie terenkripsi Laravel juga berawalan eyJ ({"iv"), jadi yang dicari header JWT.
        $this->assertStringNotContainsString('eyJhbGci', $setCookie, 'JWT Supabase tidak boleh dikirim ke browser');
        $this->assertSame(1, $this->ctx()->countAudit($this->f('company')['id'], 'auth.login', $this->f('barista')['id']));
    }

    public function test_next_dipakai_hanya_jika_aman_dan_diizinkan(): void
    {
        $this->assertSame('/admin/users', Browser::location($this->browser()->post('/login', [
            'email' => $this->f('superAdmin')['email'], 'password' => $this->f('superAdmin')['password'], 'next' => '/admin/users',
        ])));
        $this->assertSame('/admin', Browser::location($this->browser()->post('/login', [
            'email' => $this->f('superAdmin')['email'], 'password' => $this->f('superAdmin')['password'], 'next' => '//evil.com',
        ])));
    }

    // --- akses berdasarkan role ----------------------------------------------------

    public function test_super_admin_dashboard_dan_halaman_pengguna(): void
    {
        $browser = $this->login($this->f('superAdmin'));
        $dashboard = $browser->get('/admin');
        $this->assertSame(200, $dashboard->getStatusCode());
        $page = Browser::page($dashboard);
        $this->assertSame('admin/dashboard', $page['component']);
        $this->assertSame('E2E Super Admin', $page['props']['auth']['user']['fullName']);
        $this->assertSame($this->f('company')['name'], $page['props']['auth']['user']['company']);
        $this->assertSame('no-store, private', $dashboard->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('eyJhbGci', (string) $dashboard->getBody());

        $users = Browser::page($browser->get('/admin/users'));
        $this->assertContains($this->f('barista')['email'], array_column($users['props']['users'], 'email'));
    }

    public function test_barista_halaman_admin_403_sungguhan(): void
    {
        $browser = $this->login($this->f('barista'));
        foreach (['/admin', '/admin/users', '/admin/roles', '/admin/audit'] as $path) {
            $response = $browser->get($path);
            $this->assertSame(403, $response->getStatusCode(), $path);
            $this->assertSame('errors/forbidden', Browser::page($response)['component'], $path);
            $this->assertStringNotContainsString('Halo, ', (string) $response->getBody(), $path);
        }
    }

    public function test_barista_staff_dan_kds_kasir_tanpa_kds(): void
    {
        $barista = $this->login($this->f('barista'));
        $staff = Browser::page($barista->get('/staff'));
        $this->assertSame('staff/home', $staff['component']);
        $this->assertSame('E2E Barista', $staff['props']['auth']['user']['fullName']);
        $this->assertSame(200, $barista->get('/kds')->getStatusCode());

        $this->assertSame(403, $this->login($this->f('cashier'))->get('/kds')->getStatusCode());
    }

    public function test_barista_memanggil_mutasi_admin_langsung_ditolak(): void
    {
        $browser = $this->login($this->f('barista'));
        $company = $this->f('company');

        $this->assertSame(403, $browser->post('/admin/users/set-active', ['userId' => $this->f('cashier')['id'], 'isActive' => false])->getStatusCode());
        $this->assertSame(403, $browser->post('/admin/users/assign-role', [
            'userId' => $this->f('barista')['id'], 'roleId' => $company['roles']['super_admin'], 'outletId' => null,
        ])->getStatusCode());
        $this->assertSame(403, $browser->post('/admin/roles/set-permission', [
            'roleId' => $company['roles']['barista'], 'permissionCode' => 'user.manage', 'granted' => true,
        ])->getStatusCode());

        $this->assertTrue((bool) DB::table('users')->where('id', $this->f('cashier')['id'])->value('is_active'));
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $this->f('barista')['id'])->count());
        $this->assertFalse(DB::table('role_permissions')->where(['role_id' => $company['roles']['barista'], 'permission_code' => 'user.manage'])->exists());
    }

    // --- logout & status akun ------------------------------------------------------

    public function test_logout_salinan_cookie_lama_tidak_berlaku_dan_refresh_token_dicabut(): void
    {
        $browser = $this->login($this->f('superAdmin'));
        $stolen = $browser->copy();
        [$sessionId, $tokens] = $this->storedTokens($this->f('superAdmin')['id']);
        $this->assertSame(200, $stolen->get('/admin')->getStatusCode());

        $logout = $browser->post('/logout');
        $this->assertSame('/login', Browser::location($logout));
        $this->assertGreaterThanOrEqual(1, $this->ctx()->countAudit($this->f('company')['id'], 'auth.logout', $this->f('superAdmin')['id']));

        $this->assertSame(302, $stolen->get('/admin')->getStatusCode());
        $this->assertFalse(DB::table('auth.sessions')->where('id', $sessionId)->exists());
        try {
            app(SupabaseAuth::class)->refresh($tokens['refresh']);
            $this->fail('refresh token masih berlaku');
        } catch (SupabaseAuthException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_session_dicabut_di_supabase_langsung_berlaku_di_aplikasi(): void
    {
        $browser = $this->login($this->f('cashier'));
        $this->assertSame(200, $browser->get('/staff')->getStatusCode());
        [, $tokens] = $this->storedTokens($this->f('cashier')['id']);

        app(SupabaseAuth::class)->signOut($tokens['access'], 'global');

        $this->assertSame(302, $browser->get('/staff')->getStatusCode());
    }

    public function test_akun_nonaktif_tidak_bisa_membuka_staff_walau_sesi_masih_ada(): void
    {
        $browser = $this->login($this->f('cashier'));
        DB::table('users')->where('id', $this->f('cashier')['id'])->update(['is_active' => false]);
        try {
            $staff = $browser->get('/staff');
            $this->assertSame('/login', Browser::location($staff));
            $login = Browser::page($browser->get('/login'));
            $this->assertSame('Akun Anda dinonaktifkan. Hubungi admin.', $login['props']['reason']);
            $this->assertNull($login['props']['auth']);
        } finally {
            DB::table('users')->where('id', $this->f('cashier')['id'])->update(['is_active' => true]);
        }
    }

    public function test_token_hampir_kedaluwarsa_di_refresh_server_tanpa_logout(): void
    {
        $browser = $this->login($this->f('barista'));
        [$sessionId, $tokens] = $this->storedTokens($this->f('barista')['id']);
        $this->putTokens($sessionId, [...$tokens, 'expiresAt' => time() + 5]);

        $this->assertSame(200, $browser->get('/staff')->getStatusCode());
        [, $after] = $this->storedTokens($this->f('barista')['id']);
        $this->assertNotSame($tokens['refresh'], $after['refresh']);
        $this->assertGreaterThan(time() + 600, $after['expiresAt']);
        $this->assertSame(200, $browser->get('/staff')->getStatusCode());
    }

    // --- undangan dan reset password ----------------------------------------------

    public function test_undangan_sampai_staf_bisa_login_dengan_password_sendiri(): void
    {
        $admin = $this->login($this->f('superAdmin'));
        $email = $this->ctx()->newEmail();
        $company = $this->f('company');
        $invite = $admin->post('/admin/users/invite', [
            'email' => $email, 'fullName' => 'Staf Undangan E2E', 'roleId' => $company['roles']['barista'], 'outletId' => $company['outletId'],
        ]);
        $this->assertSame(302, $invite->getStatusCode());
        $link = Browser::page($admin->get('/admin/users'))['flash']['link'];
        $this->assertStringStartsWith($this->base().'/auth/confirm?token_hash=', $link);
        // Link hanya sekali tampil.
        $this->assertArrayNotHasKey('link', Browser::page($admin->get('/admin/users'))['flash'] ?? []);

        $this->setPasswordThroughLink($link, 'Staf Undangan E2E');
        $userId = DB::table('auth.users')->where('email', $email)->value('id');
        $this->assertSame(1, $this->ctx()->countAudit($company['id'], 'auth.password_set', $userId));
    }

    public function test_reset_password_oleh_admin(): void
    {
        $admin = $this->login($this->f('superAdmin'));
        $cashier = $this->f('cashier');
        $this->assertSame(302, $admin->post('/admin/users/recovery', ['userId' => $cashier['id']])->getStatusCode());
        $link = Browser::page($admin->get('/admin/users'))['flash']['link'];
        $this->assertStringContainsString('type=recovery', $link);

        $password = $this->setPasswordThroughLink($link, $cashier['fullName']);
        // Password lama tidak berlaku lagi.
        $old = $this->browser()->post('/login', ['email' => $cashier['email'], 'password' => $cashier['password']]);
        $this->assertNotSame('/staff', Browser::location($old));
        static::$fixture['cashier']['password'] = $password;
    }

    public function test_link_tidak_valid_kembali_ke_login_dengan_pesan(): void
    {
        $browser = $this->browser();
        $response = $browser->get('/auth/confirm?token_hash=palsu&type=invite&next=/auth/set-password');
        $this->assertSame('/login?error=link', Browser::location($response));
        $this->assertStringContainsString('tidak valid', Browser::page($browser->get('/login?error=link'))['props']['notice']);
        $this->assertSame('/login?error=link', Browser::location($browser->get('/auth/confirm?token_hash=x&type=signup')));
    }

    public function test_header_keamanan_dan_csp_di_server_sungguhan(): void
    {
        $response = $this->browser()->get('/login');
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{32}'/", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('', $response->getHeaderLine('Strict-Transport-Security'), 'HSTS hanya di production');
    }

    /** @return string password baru */
    private function setPasswordThroughLink(string $link, string $fullName): string
    {
        $browser = $this->browser();
        $confirm = $browser->get(substr($link, strlen($this->base())));
        $this->assertSame('/auth/set-password', Browser::location($confirm));
        $page = Browser::page($browser->get('/auth/set-password'));
        $this->assertSame('auth/set-password', $page['component']);
        $this->assertSame($fullName, $page['props']['fullName']);

        $browser->post('/auth/set-password', ['password' => 'pendek', 'password_confirmation' => 'pendek']);
        $this->assertSame('Password minimal 12 karakter.', Browser::page($browser->get('/auth/set-password'))['props']['errors']['password']);

        $password = LiveContext::newPassword();
        $saved = $browser->post('/auth/set-password', ['password' => $password, 'password_confirmation' => $password]);
        $this->assertSame('/staff', Browser::location($saved));

        $email = DB::table('auth.users')->where('id', DB::table('users')->where('full_name', $fullName)->value('id'))->value('email');
        $fresh = $this->browser()->post('/login', ['email' => $email, 'password' => $password]);
        $this->assertSame('/staff', Browser::location($fresh));

        return $password;
    }
}
