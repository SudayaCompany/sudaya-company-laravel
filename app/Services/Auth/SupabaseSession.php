<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Menghubungkan sesi Laravel dengan session Supabase. Sesi Laravel hanya memegang
 * id session Supabase; tokennya ada di store terpisah. Kalau ikut di sesi, request
 * paralel bisa menimpa refresh token baru dengan yang lama, dan pemakaian ulang
 * token lama setelah masa toleransi membuat Supabase mencabut seluruh session.
 */
final class SupabaseSession
{
    private const SESSION_KEY = 'supabase_session_id';

    /** Refresh sebelum token kedaluwarsa, sama seperti klien Supabase. */
    private const REFRESH_MARGIN_SECONDS = 60;

    private const TOKEN_TTL_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        private SupabaseAuth $auth,
        private SupabaseTokenVerifier $verifier,
        private AccessLoader $access,
    ) {}

    /** Memverifikasi token baru, lalu membuat sesi Laravel dengan id baru (anti session fixation). */
    public function start(Request $request, User $user, SupabaseTokens $tokens): void
    {
        $claims = $this->verifier->verify($tokens->accessToken);
        if ($claims['sub'] !== $user->id) {
            throw new InvalidTokenException('subject');
        }

        $previous = $request->session()->get(self::SESSION_KEY);
        if (is_string($previous)) {
            $this->store()->forget($this->key($previous));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $claims['session_id']);
        $this->put($claims['session_id'], $tokens);
    }

    /**
     * Memeriksa sesi pada setiap request: token sah dan belum kedaluwarsa, milik
     * user ini, dan session belum dicabut di auth.sessions.
     *
     * @throws SupabaseAuthException Supabase tidak bisa dihubungi, sah atau tidaknya sesi belum diketahui
     */
    public function isValid(Request $request, string $userId): bool
    {
        $sessionId = $request->session()->get(self::SESSION_KEY);
        if (! is_string($sessionId)) {
            return false;
        }
        $tokens = $this->tokensFor($sessionId);
        if ($tokens === null) {
            return false;
        }
        if ($tokens->expiresWithin(self::REFRESH_MARGIN_SECONDS)) {
            $tokens = $this->refresh($sessionId, $tokens);
            if ($tokens === null) {
                return false;
            }
        }

        try {
            $claims = $this->verifier->verify($tokens->accessToken);
        } catch (InvalidTokenException) {
            return false;
        }

        return $claims['sub'] === $userId
            && $claims['session_id'] === $sessionId
            && $this->access->isSessionActive($sessionId, $userId);
    }

    public function accessToken(Request $request): ?string
    {
        $sessionId = $request->session()->get(self::SESSION_KEY);

        return is_string($sessionId) ? $this->tokensFor($sessionId)?->accessToken : null;
    }

    /** Menutup session Supabase perangkat ini. Sesi Laravel tetap berakhir walau Supabase gagal. */
    public function end(Request $request): void
    {
        $sessionId = $request->session()->get(self::SESSION_KEY);
        if (! is_string($sessionId)) {
            return;
        }
        $tokens = $this->tokensFor($sessionId);
        if ($tokens !== null) {
            try {
                $this->auth->signOut($tokens->accessToken);
            } catch (SupabaseAuthException $error) {
                Log::warning('supabase_signout_failed', ['code' => $error->errorCode, 'status' => $error->status]);
            }
        }
        $this->forget($request);
    }

    public function forget(Request $request): void
    {
        $sessionId = $request->session()->pull(self::SESSION_KEY);
        if (is_string($sessionId)) {
            $this->store()->forget($this->key($sessionId));
        }
    }

    /** Null berarti session sudah tidak bisa dipulihkan. */
    private function refresh(string $sessionId, SupabaseTokens $stale): ?SupabaseTokens
    {
        try {
            return $this->store()->lock($this->key($sessionId).':refresh', 20)->block(15, function () use ($sessionId) {
                // Request lain mungkin sudah me-refresh selama menunggu lock.
                $current = $this->tokensFor($sessionId);
                if ($current === null || ! $current->expiresWithin(self::REFRESH_MARGIN_SECONDS)) {
                    return $current;
                }
                try {
                    $tokens = $this->auth->refresh($current->refreshToken)['tokens'];
                } catch (SupabaseAuthException $error) {
                    if ($error->isTransient()) {
                        throw $error;
                    }
                    $this->store()->forget($this->key($sessionId));

                    return null;
                }
                $this->put($sessionId, $tokens);

                return $tokens;
            });
        } catch (SupabaseAuthException|LockTimeoutException $error) {
            // Token lama masih berlaku beberapa detik: request ini tetap bisa dilayani.
            if (! $stale->expiresWithin(0)) {
                return $stale;
            }
            throw $error instanceof SupabaseAuthException ? $error : new SupabaseAuthException('refresh_lock_timeout');
        }
    }

    private function tokensFor(string $sessionId): ?SupabaseTokens
    {
        $stored = $this->store()->get($this->key($sessionId));
        if (! is_string($stored)) {
            return null;
        }
        $tokens = json_decode(Crypt::decryptString($stored), true);

        return new SupabaseTokens($tokens['access'], $tokens['refresh'], $tokens['expiresAt']);
    }

    private function put(string $sessionId, SupabaseTokens $tokens): void
    {
        $this->store()->put($this->key($sessionId), Crypt::encryptString(json_encode([
            'access' => $tokens->accessToken,
            'refresh' => $tokens->refreshToken,
            'expiresAt' => $tokens->expiresAt,
        ])), self::TOKEN_TTL_SECONDS);
    }

    private function key(string $sessionId): string
    {
        return "supabase-tokens:{$sessionId}";
    }

    private function store(): Repository
    {
        return Cache::store('auth_tokens');
    }
}
