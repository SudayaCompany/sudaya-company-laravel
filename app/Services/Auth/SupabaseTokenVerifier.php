<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Memverifikasi JWT Supabase secara lokal dengan JWKS: signature, algoritma,
 * masa berlaku, issuer, dan audience. Payload tidak pernah dibaca tanpa lolos sini.
 */
final class SupabaseTokenVerifier
{
    private const JWKS_CACHE_KEY = 'supabase:jwks';

    /** Supabase meng-cache JWKS di edge sekitar 10 menit. */
    private const JWKS_TTL_SECONDS = 600;

    private const REFETCH_GUARD_SECONDS = 60;

    /** Toleransi selisih jam server terhadap Supabase. */
    private const LEEWAY_SECONDS = 30;

    /**
     * @return array{sub: string, session_id: string, exp: int}
     *
     * @throws InvalidTokenException token tidak sah
     * @throws SupabaseAuthException JWKS tidak bisa diambil
     */
    public function verify(#[\SensitiveParameter] string $jwt): array
    {
        $keys = $this->keys($jwt);

        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $claims = (array) JWT::decode($jwt, $keys);
        } catch (UnexpectedValueException|\InvalidArgumentException|\DomainException $error) {
            throw new InvalidTokenException($error::class, previous: $error);
        }

        $issuer = rtrim((string) config('services.supabase.url'), '/').'/auth/v1';
        $audience = $claims['aud'] ?? null;
        if (
            ($claims['iss'] ?? null) !== $issuer
            || ! in_array('authenticated', is_array($audience) ? $audience : [$audience], true)
            || ($claims['role'] ?? null) !== 'authenticated'
            || ! is_string($claims['sub'] ?? null)
            || ! is_string($claims['session_id'] ?? null)
            || ! is_int($claims['exp'] ?? null)
        ) {
            throw new InvalidTokenException('claims');
        }

        return ['sub' => $claims['sub'], 'session_id' => $claims['session_id'], 'exp' => $claims['exp']];
    }

    /** @return array<string, Key> */
    private function keys(#[\SensitiveParameter] string $jwt): array
    {
        $keys = $this->parse(Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_TTL_SECONDS, fn () => $this->fetch()));

        // Kunci baru setelah rotasi belum ada di cache. Dibatasi agar token palsu
        // dengan kid acak tidak bisa memaksa panggilan JWKS di setiap request.
        $kid = $this->kid($jwt);
        if ($kid !== null && ! isset($keys[$kid]) && Cache::add(self::JWKS_CACHE_KEY.':refetch', true, self::REFETCH_GUARD_SECONDS)) {
            $fresh = $this->fetch();
            Cache::put(self::JWKS_CACHE_KEY, $fresh, self::JWKS_TTL_SECONDS);
            $keys = $this->parse($fresh);
        }

        return $keys;
    }

    /** @return array<string, Key> */
    private function parse(array $jwks): array
    {
        try {
            return JWK::parseKeySet($jwks);
        } catch (Throwable $error) {
            throw new SupabaseAuthException('invalid_jwks');
        }
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function fetch(): array
    {
        try {
            $response = Http::baseUrl((string) config('services.supabase.url'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get('/auth/v1/.well-known/jwks.json');
        } catch (ConnectionException) {
            throw new SupabaseAuthException(null);
        }
        $keys = $response->json('keys');
        if ($response->failed() || ! is_array($keys) || $keys === []) {
            throw new SupabaseAuthException('jwks_unavailable', $response->failed() ? $response->status() : null);
        }

        return ['keys' => $keys];
    }

    private function kid(#[\SensitiveParameter] string $jwt): ?string
    {
        $header = json_decode((string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;

        return is_string($kid) ? $kid : null;
    }
}
