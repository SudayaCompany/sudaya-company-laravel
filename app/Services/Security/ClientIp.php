<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

/**
 * IP client hanya dibaca dari header yang DITULIS ULANG oleh proxy tepercaya,
 * diatur lewat TRUSTED_IP_HEADER. X-Forwarded-For bisa dikirim bebas oleh
 * browser bila tidak ada proxy di depan aplikasi, jadi tidak dipakai mentah.
 */
final class ClientIp
{
    private const PATTERN = '/^[0-9a-fA-F:.]{2,45}$/';

    public static function trusted(Request $request): ?string
    {
        $header = trim((string) config('sudaya.trusted_ip_header'));
        if ($header === '') {
            return null;
        }
        $raw = $request->headers->get($header);
        if ($raw === null || $raw === '') {
            return null;
        }
        // Jika proxy menambahkan (bukan menimpa), entri paling kanan yang ditulis proxy.
        $parts = explode(',', $raw);
        $value = trim(end($parts));

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
