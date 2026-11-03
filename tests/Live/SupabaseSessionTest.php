<?php

namespace Tests\Live;

use App\Services\Auth\InvalidTokenException;
use App\Services\Auth\SupabaseAuth;
use App\Services\Auth\SupabaseAuthException;
use App\Services\Auth\SupabaseSession;
use App\Services\Auth\SupabaseTokens;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Siklus sesi dengan Supabase Auth sungguhan: refresh, gangguan, dan pencabutan. */
class SupabaseSessionTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $company = $ctx->createCompany('sess');

        return ['company' => $company, 'staff' => $ctx->createUser($company, [['role' => 'cashier', 'outlet' => 'outlet']], true)];
    }

    /** @return array{0: Request, 1: SupabaseSession, 2: string} */
    private function signedIn(): array
    {
        $staff = static::$fixture['staff'];
        ['tokens' => $tokens] = app(SupabaseAuth::class)->signInWithPassword($staff['email'], $staff['password']);
        $request = Request::create('/');
        $store = app('session')->driver();
        $store->start();
        $request->setLaravelSession($store);
        $session = app(SupabaseSession::class);
        $session->start($request, $this->ctx()->load($staff['id']), $tokens);

        return [$request, $session, $request->session()->get('supabase_session_id')];
    }

    private function stored(string $sessionId): array
    {
        return json_decode(Crypt::decryptString(Cache::store('auth_tokens')->get("supabase-tokens:{$sessionId}")), true);
    }

    private function expire(string $sessionId): void
    {
        $tokens = $this->stored($sessionId);
        $tokens['expiresAt'] = time() + 5;
        Cache::store('auth_tokens')->put("supabase-tokens:{$sessionId}", Crypt::encryptString(json_encode($tokens)), 600);
    }

    public function test_sesi_baru_sah_dan_token_tidak_disimpan_di_sesi_laravel(): void
    {
        [$request, $session, $sessionId] = $this->signedIn();

        $this->assertTrue($session->isValid($request, static::$fixture['staff']['id']));
        $this->assertTrue(DB::table('auth.sessions')->where('id', $sessionId)->exists());
        $this->assertStringNotContainsString($this->stored($sessionId)['refresh'], json_encode($request->session()->all()));
        $this->assertStringNotContainsString($this->stored($sessionId)['refresh'], Cache::store('auth_tokens')->get("supabase-tokens:{$sessionId}"));

        $session->end($request);
    }

    public function test_token_hampir_kedaluwarsa_di_refresh_dan_session_tetap_sama(): void
    {
        [$request, $session, $sessionId] = $this->signedIn();
        $before = $this->stored($sessionId);
        $this->expire($sessionId);

        $this->assertTrue($session->isValid($request, static::$fixture['staff']['id']));
        $after = $this->stored($sessionId);
        $this->assertNotSame($before['refresh'], $after['refresh']);
        $this->assertGreaterThan(time() + 600, $after['expiresAt']);
        $this->assertSame($sessionId, $request->session()->get('supabase_session_id'));

        // Refresh token lama tidak bisa dipakai lagi di luar masa toleransi Supabase.
        $session->end($request);
    }

    public function test_supabase_tidak_terjangkau_sesi_tidak_dihapus(): void
    {
        [$request, $session, $sessionId] = $this->signedIn();
        $this->expire($sessionId);
        // Endpoint token putus; JWKS sudah di cache dari login. URL tidak diubah karena juga menjadi issuer.
        Http::fake(['*/auth/v1/token*' => fn () => throw new ConnectionException('down')]);
        // Masih ada 5 detik: request tetap dilayani dengan token lama.
        $this->assertTrue($session->isValid($request, static::$fixture['staff']['id']));

        // Sudah lewat: tidak bisa diputuskan, jadi error sementara, bukan logout.
        $tokens = $this->stored($sessionId);
        $tokens['expiresAt'] = time() - 1;
        Cache::store('auth_tokens')->put("supabase-tokens:{$sessionId}", Crypt::encryptString(json_encode($tokens)), 600);
        try {
            $session->isValid($request, static::$fixture['staff']['id']);
            $this->fail('tidak ada error sementara');
        } catch (SupabaseAuthException $error) {
            $this->assertTrue($error->isTransient());
        }
        $this->assertNotNull(Cache::store('auth_tokens')->get("supabase-tokens:{$sessionId}"));

        Http::swap(new Factory);
        $this->assertTrue($session->isValid($request, static::$fixture['staff']['id']));
        $session->end($request);
    }

    public function test_session_dicabut_di_supabase_langsung_tidak_berlaku(): void
    {
        [$request, $session, $sessionId] = $this->signedIn();
        // Mis. logout dari perangkat lain dengan scope global, atau dicabut admin.
        app(SupabaseAuth::class)->signOut($this->stored($sessionId)['access'], 'global');

        $this->assertFalse(DB::table('auth.sessions')->where('id', $sessionId)->exists());
        $this->assertFalse($session->isValid($request, static::$fixture['staff']['id']));
        $session->forget($request);
    }

    public function test_sesi_milik_user_lain_ditolak(): void
    {
        [$request, $session] = $this->signedIn();
        $this->assertFalse($session->isValid($request, static::$fixture['company']['id']));
        $session->end($request);
    }

    public function test_logout_mencabut_refresh_token(): void
    {
        [$request, $session, $sessionId] = $this->signedIn();
        $refresh = $this->stored($sessionId)['refresh'];
        $session->end($request);

        $this->assertNull(Cache::store('auth_tokens')->get("supabase-tokens:{$sessionId}"));
        $this->assertFalse(DB::table('auth.sessions')->where('id', $sessionId)->exists());
        try {
            app(SupabaseAuth::class)->refresh($refresh);
            $this->fail('refresh token masih berlaku');
        } catch (SupabaseAuthException $error) {
            $this->assertFalse($error->isTransient());
        }
    }

    public function test_token_yang_tidak_cocok_dengan_user_ditolak_saat_mulai(): void
    {
        $staff = static::$fixture['staff'];
        $other = $this->ctx()->createUser(static::$fixture['company'], [['role' => 'barista']], true);
        ['tokens' => $tokens] = app(SupabaseAuth::class)->signInWithPassword($other['email'], $other['password']);
        $request = Request::create('/');
        $request->setLaravelSession(tap(app('session')->driver())->start());

        $this->expectException(InvalidTokenException::class);
        try {
            app(SupabaseSession::class)->start($request, $this->ctx()->load($staff['id']), $tokens);
        } finally {
            app(SupabaseAuth::class)->signOut($tokens->accessToken);
        }
    }

    public function test_token_palsu_tidak_diterima_walau_session_id_benar(): void
    {
        [$request, $session, $sessionId] = $this->signedIn();
        $real = $this->stored($sessionId);
        [$header, $payload] = explode('.', $real['access']);
        Cache::store('auth_tokens')->put("supabase-tokens:{$sessionId}", Crypt::encryptString(json_encode([
            ...$real, 'access' => "{$header}.{$payload}.".rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '='),
        ])), 600);

        $this->assertFalse($session->isValid($request, static::$fixture['staff']['id']));
        app(SupabaseAuth::class)->signOut($real['access']);
        $session->forget($request);
        $this->assertInstanceOf(SupabaseTokens::class, new SupabaseTokens('a', 'b', 1));
    }
}
