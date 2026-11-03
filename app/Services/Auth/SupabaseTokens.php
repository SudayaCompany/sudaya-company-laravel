<?php

namespace App\Services\Auth;

/** Pasangan token session Supabase. Tidak pernah dikirim ke browser. */
final readonly class SupabaseTokens
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        #[\SensitiveParameter] public string $refreshToken,
        public int $expiresAt,
    ) {}

    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt - time() <= $seconds;
    }

    /** @return array{expiresAt: int} */
    public function __debugInfo(): array
    {
        return ['expiresAt' => $this->expiresAt];
    }
}
