<?php

namespace App\Http\Middleware;

use App\Services\Auth\SupabaseAuthException;
use App\Services\Auth\SupabaseSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi Laravel saja tidak cukup: setiap request memeriksa token Supabase dan
 * auth.sessions, agar logout atau pencabutan session langsung berlaku.
 */
class VerifySupabaseSession
{
    public function __construct(private SupabaseSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Auth::id();
        if ($userId === null) {
            $this->session->forget($request);

            return $next($request);
        }

        try {
            $valid = $this->session->isValid($request, $userId);
        } catch (SupabaseAuthException $error) {
            // Sesi tidak dihapus: begitu Supabase pulih, user tidak perlu login ulang.
            Log::warning('supabase_auth_unavailable', ['code' => $error->errorCode, 'status' => $error->status]);
            abort(503);
        }

        if (! $valid) {
            $this->session->forget($request);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
