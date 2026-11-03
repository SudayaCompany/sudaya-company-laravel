<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Orders\CheckoutInput;
use App\Services\Orders\CheckoutLimits;
use App\Services\Orders\GuestTokens;
use App\Services\Orders\OrderEngine;
use App\Services\Security\ClientIp;
use App\Services\Security\RateLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Checkout guest (QR, web, kiosk), padanan app/api/orders/route.ts. Order dibuat
 * oleh order engine yang sama dengan POS. Token CSRF diperiksa middleware Laravel
 * sebelum controller ini; origin diperiksa di sini.
 */
class CheckoutController
{
    private const MAX_BODY_BYTES = 64 * 1024;

    public function __invoke(Request $request, OrderEngine $orders, RateLimits $limits): JsonResponse
    {
        if (! $this->sameOrigin($request)) {
            return $this->json(['ok' => false, 'error' => 'Permintaan ditolak.'], 403);
        }
        if (! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            return $this->json(['ok' => false, 'error' => 'Permintaan tidak valid.'], 415);
        }
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->json(['ok' => false, 'error' => 'Pesanan terlalu besar.'], 413);
        }
        $body = json_decode($request->getContent(), true);
        $outlet = is_array($body) ? ($body['outlet'] ?? null) : null;
        $channel = is_array($body) ? ($body['channel'] ?? null) : null;
        $checkout = is_array($body) ? ($body['checkout'] ?? null) : null;
        if (! is_string($outlet) || trim($outlet) === '' || strlen($outlet) > 32 || ! in_array($channel, CheckoutInput::PUBLIC_CHANNELS, true) || ! is_array($checkout)) {
            return $this->json(['ok' => false, 'error' => 'Permintaan tidak valid. Muat ulang halaman.'], 400);
        }

        $existingDevice = CheckoutLimits::readDeviceId($request->cookie(CheckoutLimits::DEVICE_COOKIE));
        $deviceId = $existingDevice ?? CheckoutLimits::newDeviceId();
        $ip = ClientIp::trusted($request);
        $cookies = $existingDevice === null
            ? [$this->cookie(CheckoutLimits::DEVICE_COOKIE, $deviceId, CheckoutLimits::DEVICE_COOKIE_MINUTES, '/')]
            : [];

        try {
            $replay = CheckoutLimits::isReplay($outlet, $checkout['idempotencyKey'] ?? null);
            $limit = $limits->consume(CheckoutLimits::rules($channel, $outlet, $deviceId, $ip, $replay));
            if (! $limit['ok']) {
                $minutes = (int) ceil($limit['retryAfterSeconds'] / 60);

                return $this->json([
                    'ok' => false,
                    'error' => "Terlalu banyak pesanan dari perangkat/jaringan ini. Coba lagi dalam {$minutes} menit atau pesan di kasir.",
                    'retryAfter' => $limit['retryAfterSeconds'],
                ], 429, $cookies)->header('Retry-After', (string) $limit['retryAfterSeconds']);
            }

            $created = $orders->create($channel, ['code' => $outlet], null, $checkout, new AuditContext($ip, $request->userAgent()));

            // Token akses halaman status: cookie HttpOnly, hanya untuk path status.
            // Kiosk adalah perangkat bersama, jadi tidak menyimpan token.
            if ($created['accessToken'] !== null && $channel !== 'kiosk') {
                $cookies[] = $this->cookie(GuestTokens::cookieName($created['orderId']), $created['accessToken'], GuestTokens::COOKIE_MINUTES, GuestTokens::COOKIE_PATH);
            }

            return $this->json([
                'ok' => true,
                'orderId' => $created['orderId'],
                'reference' => $created['reference'],
                'total' => $created['total'],
            ], $created['replayed'] ? 200 : 201, $cookies);
        } catch (AccessException $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->reason === 'conflict' ? 409 : 422, $cookies);
        } catch (Throwable $error) {
            report($error);
            Log::error('checkout_failed', ['exception' => $error::class]);

            return $this->json(['ok' => false, 'error' => 'Pesanan gagal dikirim. Coba lagi.'], 500, $cookies);
        }
    }

    /** Tolak request lintas-origin (defense-in-depth; JSON sudah memaksa preflight CORS). */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return $request->headers->get('Sec-Fetch-Site') !== 'cross-site';
        }
        $parts = parse_url($origin);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $host === strtolower($request->getHttpHost());
    }

    /** @param list<Cookie> $cookies */
    private function json(array $body, int $status, array $cookies = []): JsonResponse
    {
        $response = response()->json($body, $status)->header('Cache-Control', 'no-store');
        foreach ($cookies as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    private function cookie(string $name, string $value, int $minutes, string $path): Cookie
    {
        $secure = app()->isProduction() || (bool) config('session.secure');

        return cookie($name, $value, $minutes, $path, null, $secure, true, false, 'lax');
    }
}
