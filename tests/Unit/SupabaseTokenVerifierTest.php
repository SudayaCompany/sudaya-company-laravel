<?php

namespace Tests\Unit;

use App\Services\Auth\InvalidTokenException;
use App\Services\Auth\SupabaseAuthException;
use App\Services\Auth\SupabaseTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Kunci dibuat per test run, jadi tidak ada kunci privat yang disimpan di repo. */
class SupabaseTokenVerifierTest extends TestCase
{
    private const URL = 'https://project.supabase.test';

    private static array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => self::URL]);
        Http::preventStrayRequests();
    }

    private static function key(string $kid): array
    {
        if (! isset(self::$keys[$kid])) {
            $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
            // PHP Windows portabel tidak menemukan openssl.cnf tanpa diberi tahu.
            $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            if (is_file($config)) {
                $options['config'] = $config;
            }
            $key = openssl_pkey_new($options);
            openssl_pkey_export($key, $pem, null, $options);
            $ec = openssl_pkey_get_details($key)['ec'];
            $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            self::$keys[$kid] = [
                'pem' => $pem,
                'jwk' => ['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => $kid, 'x' => $b64($ec['x']), 'y' => $b64($ec['y'])],
            ];
        }

        return self::$keys[$kid];
    }

    private function token(array $override = [], string $kid = 'k1'): string
    {
        $claims = [
            'iss' => self::URL.'/auth/v1',
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'sub' => '6f0c2b7e-0000-4000-8000-000000000001',
            'session_id' => '6f0c2b7e-0000-4000-8000-000000000002',
            'iat' => time(),
            'exp' => time() + 3600,
            ...$override,
        ];

        return JWT::encode($claims, self::key($kid)['pem'], 'ES256', $kid);
    }

    private function serveKeys(string ...$kids): void
    {
        Http::fake([self::URL.'/auth/v1/.well-known/jwks.json' => Http::response([
            'keys' => array_map(fn (string $kid) => self::key($kid)['jwk'], $kids),
        ])]);
    }

    public function test_token_sah_mengembalikan_claim(): void
    {
        $this->serveKeys('k1');
        $claims = app(SupabaseTokenVerifier::class)->verify($this->token());
        $this->assertSame('6f0c2b7e-0000-4000-8000-000000000002', $claims['session_id']);
    }

    public function test_token_kedaluwarsa_ditolak(): void
    {
        $this->serveKeys('k1');
        $this->expectException(InvalidTokenException::class);
        app(SupabaseTokenVerifier::class)->verify($this->token(['iat' => time() - 7200, 'exp' => time() - 3600]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function wrongClaims(): iterable
    {
        yield 'issuer lain' => [['iss' => 'https://evil.test/auth/v1']];
        yield 'audience lain' => [['aud' => 'anon']];
        yield 'role anon' => [['role' => 'anon']];
        yield 'tanpa session' => [['session_id' => null]];
    }

    #[DataProvider('wrongClaims')]
    public function test_claim_yang_tidak_sesuai_ditolak(array $override): void
    {
        $this->serveKeys('k1');
        $this->expectException(InvalidTokenException::class);
        app(SupabaseTokenVerifier::class)->verify($this->token($override));
    }

    public function test_tanda_tangan_kunci_lain_ditolak(): void
    {
        // Token ditandatangani k2 tapi mengaku k1.
        $this->serveKeys('k1');
        $forged = JWT::encode(['sub' => 'x', 'exp' => time() + 60], self::key('k2')['pem'], 'ES256', 'k1');
        $this->expectException(InvalidTokenException::class);
        app(SupabaseTokenVerifier::class)->verify($forged);
    }

    public function test_alg_none_dan_hs256_ditolak(): void
    {
        $this->serveKeys('k1');
        $verifier = app(SupabaseTokenVerifier::class);
        $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
        $claims = ['iss' => self::URL.'/auth/v1', 'aud' => 'authenticated', 'role' => 'authenticated', 'sub' => 'x', 'session_id' => 'y', 'exp' => time() + 60];

        foreach ([
            $b64(['alg' => 'none', 'kid' => 'k1']).'.'.$b64($claims).'.',
            JWT::encode($claims, json_encode(self::key('k1')['jwk']), 'HS256', 'k1'),
        ] as $token) {
            try {
                $verifier->verify($token);
                $this->fail('token diterima');
            } catch (InvalidTokenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_kunci_baru_setelah_rotasi_diambil_sekali(): void
    {
        Http::fake([self::URL.'/auth/v1/.well-known/jwks.json' => Http::sequence()
            ->push(['keys' => [self::key('k1')['jwk']]])
            ->push(['keys' => [self::key('k1')['jwk'], self::key('k2')['jwk']]])]);
        $verifier = app(SupabaseTokenVerifier::class);
        $verifier->verify($this->token());
        $verifier->verify($this->token(kid: 'k2'));

        // kid acak tidak memaksa ambil JWKS lagi dalam jendela 60 detik.
        try {
            $verifier->verify($this->token(kid: 'k3'));
        } catch (InvalidTokenException) {
        }
        Http::assertSentCount(2);
    }

    public function test_jwks_tidak_bisa_diambil_bukan_berarti_token_palsu(): void
    {
        Http::fake([self::URL.'/*' => Http::response('', 503)]);
        $this->expectException(SupabaseAuthException::class);
        app(SupabaseTokenVerifier::class)->verify($this->token());
    }
}
