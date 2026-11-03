<?php

namespace App\Services\Orders;

/**
 * Token akses guest untuk halaman status order. Diperlakukan seperti password:
 * hanya hash yang disimpan, token asli hanya di cookie HttpOnly milik customer.
 */
final class GuestTokens
{
    /** Cookie per order, hanya dikirim ke halaman status. */
    public const COOKIE_PATH = '/order/status';

    public const COOKIE_MINUTES = 60 * 24 * 3;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token: string, hash: string} */
    public static function create(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function verify(?string $token, ?string $hash): bool
    {
        if ($token === null || $token === '' || $hash === null || strlen($token) > 100) {
            return false;
        }

        return hash_equals($hash, self::hash($token));
    }

    public static function cookieName(string $orderId): string
    {
        return 'sdy_order_'.str_replace('-', '', $orderId);
    }

    /** Topik realtime guest, diturunkan dari hash token sehingga tidak bisa ditebak dari id order. */
    public static function topic(string $hash): string
    {
        return 'order:'.substr($hash, 0, 40);
    }
}
