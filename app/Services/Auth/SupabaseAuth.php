<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** REST client Supabase Auth untuk operasi atas nama user yang sedang login. */
final class SupabaseAuth
{
    private const TIMEOUT_SECONDS = 10;

    /** @return array{tokens: SupabaseTokens, userId: string} */
    public function signInWithPassword(string $email, #[\SensitiveParameter] string $password): array
    {
        return $this->session($this->send(fn (PendingRequest $http) => $http->post(
            '/auth/v1/token?grant_type=password',
            ['email' => $email, 'password' => $password],
        )));
    }

    /** @return array{tokens: SupabaseTokens, userId: string} */
    public function refresh(#[\SensitiveParameter] string $refreshToken): array
    {
        return $this->session($this->send(fn (PendingRequest $http) => $http->post(
            '/auth/v1/token?grant_type=refresh_token',
            ['refresh_token' => $refreshToken],
        )));
    }

    /**
     * Menukar token dari link undangan atau reset password menjadi session.
     *
     * @return array{tokens: SupabaseTokens, userId: string}
     */
    public function verifyOtp(string $type, #[\SensitiveParameter] string $tokenHash): array
    {
        return $this->session($this->send(fn (PendingRequest $http) => $http->post(
            '/auth/v1/verify',
            ['type' => $type, 'token_hash' => $tokenHash],
        )));
    }

    public function updatePassword(#[\SensitiveParameter] string $accessToken, #[\SensitiveParameter] string $password): void
    {
        $this->send(fn (PendingRequest $http) => $http->withToken($accessToken)->put(
            '/auth/v1/user',
            ['password' => $password],
        ));
    }

    /** Scope local: hanya sesi perangkat ini, perangkat outlet lain tetap login. */
    public function signOut(#[\SensitiveParameter] string $accessToken, string $scope = 'local'): void
    {
        $this->send(fn (PendingRequest $http) => $http->withToken($accessToken)->post(
            '/auth/v1/logout?scope='.$scope,
        ));
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        $http = Http::baseUrl((string) config('services.supabase.url'))
            ->withHeaders(['apikey' => (string) config('services.supabase.publishable_key')])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(self::TIMEOUT_SECONDS);

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new SupabaseAuthException(null);
        }
        if ($response->failed()) {
            throw new SupabaseAuthException($response->json('error_code') ?? $response->json('error'), $response->status());
        }

        return $response;
    }

    /** @return array{tokens: SupabaseTokens, userId: string} */
    private function session(Response $response): array
    {
        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        $userId = $response->json('user.id');
        if (! is_string($access) || ! is_string($refresh) || ! is_string($userId)) {
            throw new SupabaseAuthException('invalid_response', $response->status());
        }

        return [
            'tokens' => new SupabaseTokens($access, $refresh, (int) $response->json('expires_at')),
            'userId' => $userId,
        ];
    }
}
