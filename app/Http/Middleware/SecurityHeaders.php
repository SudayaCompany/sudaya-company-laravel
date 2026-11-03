<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Kamera dan GPS dipakai halaman absensi. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Halaman tertentu (status pesanan guest) memasang kebijakan yang lebih ketat.
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), browsing-topics=()');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));

        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000');
        }
        // Halaman berisi data akun tidak boleh tersisa di cache perangkat outlet.
        if (Auth::hasUser()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $supabase = rtrim((string) config('services.supabase.url'), '/');
        $realtime = preg_replace('#^http#', 'ws', $supabase);
        $dev = Vite::isRunningHot() ? trim((string) file_get_contents(Vite::hotFile())) : '';
        $devSocket = $dev === '' ? '' : preg_replace('#^http#', 'ws', $dev);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$dev}",
            // Atribut style dipakai komponen UI, misalnya posisi toast.
            "style-src 'self' 'unsafe-inline' {$dev}",
            "img-src 'self' data: blob: {$supabase}",
            "font-src 'self' {$dev}",
            "connect-src 'self' {$supabase} {$realtime} {$dev} {$devSocket}",
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
