<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Memakai service role key: hanya di server, tidak pernah ke browser. */
final class SupabaseAuthAdmin implements AuthAdmin
{
    // Durasi ban "selamanya" versi Supabase Auth (100 tahun).
    private const BAN_FOREVER = '876000h';

    public function createInvite(string $email, string $fullName): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/auth/v1/admin/generate_link', [
            'type' => 'invite',
            'email' => $email,
            'data' => ['full_name' => $fullName],
        ]));

        return ['userId' => $response->json('id'), 'hashedToken' => $response->json('hashed_token')];
    }

    public function createRecovery(string $email): string
    {
        return $this->send(fn (PendingRequest $http) => $http->post('/auth/v1/admin/generate_link', [
            'type' => 'recovery',
            'email' => $email,
        ]))->json('hashed_token');
    }

    public function setBanned(string $userId, bool $banned): void
    {
        $this->send(fn (PendingRequest $http) => $http->put("/auth/v1/admin/users/{$userId}", [
            'ban_duration' => $banned ? self::BAN_FOREVER : 'none',
        ]));
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        $key = (string) config('services.supabase.service_role_key');
        if ($key === '') {
            throw new SupabaseAuthException('service_role_key_missing');
        }

        try {
            $response = $call(
                Http::baseUrl((string) config('services.supabase.url'))
                    ->withHeaders(['apikey' => $key])
                    ->withToken($key)
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(10),
            );
        } catch (ConnectionException) {
            throw new SupabaseAuthException(null);
        }
        if ($response->failed()) {
            throw new SupabaseAuthException($response->json('error_code') ?? $response->json('error'), $response->status());
        }

        return $response;
    }
}
