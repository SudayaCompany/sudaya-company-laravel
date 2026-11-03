<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\AccessLoader;
use App\Services\Auth\InvalidTokenException;
use App\Services\Auth\SupabaseAuth;
use App\Services\Auth\SupabaseAuthException;
use App\Services\Auth\SupabaseSession;
use App\Support\Access\RouteAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Menukar token dari link undangan atau reset password menjadi session. */
class ConfirmController
{
    private const TYPES = ['invite', 'recovery', 'email'];

    private const FAILED = '/login?error=link';

    public function __invoke(Request $request, SupabaseAuth $auth, SupabaseSession $session, AccessLoader $access): RedirectResponse
    {
        $tokenHash = $request->query('token_hash');
        $type = $request->query('type');
        if (! is_string($tokenHash) || $tokenHash === '' || ! in_array($type, self::TYPES, true)) {
            return redirect(self::FAILED);
        }

        try {
            ['tokens' => $tokens, 'userId' => $userId] = $auth->verifyOtp($type, $tokenHash);
            $user = $access->currentUser($userId);
            if ($user === null) {
                $auth->signOut($tokens->accessToken);

                return redirect(self::FAILED);
            }
            $session->start($request, $user, $tokens);
        } catch (SupabaseAuthException|InvalidTokenException) {
            return redirect(self::FAILED);
        }

        return redirect($this->destination($request->query('next')));
    }

    /** Tujuan yang diizinkan setelah verifikasi link (anti open redirect). */
    private function destination(mixed $next): string
    {
        return $next === '/auth/set-password' ? $next : (RouteAccess::safeNext($next) ?? '/login');
    }
}
