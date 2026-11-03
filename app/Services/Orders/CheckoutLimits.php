<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;

/**
 * Batas checkout guest (QR, web, kiosk), padanan lib/orders/checkout-limits.ts:
 * - per perangkat (cookie acak sdy_did): ketat, untuk satu HP customer;
 * - per IP tepercaya: longgar, karena banyak customer sah berbagi Wi-Fi outlet.
 * Retry dengan idempotency key yang sama tidak memakai kuota checkout.
 */
final class CheckoutLimits
{
    public const WINDOW_SECONDS = 10 * 60;

    public const DEVICE = 6;

    /** Kiosk: satu perangkat dipakai bergantian banyak customer. */
    public const KIOSK_DEVICE = 40;

    public const IP = 60;

    /** Cadangan bila IP tidak diketahui (tanpa proxy tepercaya): per outlet. */
    public const UNKNOWN_IP_PER_OUTLET = 120;

    public const REPLAY = 120;

    public const DEVICE_COOKIE = 'sdy_did';

    public const DEVICE_COOKIE_MINUTES = 60 * 24 * 365;

    public static function readDeviceId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{22}$/', $value) === 1 ? $value : null;
    }

    public static function newDeviceId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function rules(string $channel, string $outletCode, string $deviceId, ?string $ip, bool $replay): array
    {
        $window = self::WINDOW_SECONDS;
        $outlet = strtoupper(trim($outletCode));
        $network = $ip !== null ? "ip:{$ip}" : "outlet:{$outlet}";
        if ($replay) {
            return [
                ['key' => "checkout-replay:device:{$deviceId}", 'limit' => self::REPLAY, 'windowSeconds' => $window],
                ['key' => "checkout-replay:{$network}", 'limit' => self::REPLAY * 2, 'windowSeconds' => $window],
            ];
        }

        return [
            ['key' => "checkout:device:{$deviceId}", 'limit' => $channel === 'kiosk' ? self::KIOSK_DEVICE : self::DEVICE, 'windowSeconds' => $window],
            ['key' => "checkout:{$network}", 'limit' => $ip !== null ? self::IP : self::UNKNOWN_IP_PER_OUTLET, 'windowSeconds' => $window],
        ];
    }

    /** True jika order dengan idempotency key ini sudah ada di outlet tsb. */
    public static function isReplay(string $outletCode, mixed $idempotencyKey): bool
    {
        if (! is_string($idempotencyKey) || preg_match('/^[0-9a-f-]{36}$/i', $idempotencyKey) !== 1) {
            return false;
        }

        return DB::table('orders')
            ->join('outlets', 'outlets.id', '=', 'orders.outlet_id')
            ->where('outlets.code', strtoupper(trim($outletCode)))
            ->where('orders.idempotency_key', strtolower($idempotencyKey))
            ->exists();
    }
}
