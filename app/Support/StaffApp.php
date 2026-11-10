<?php

namespace App\Support;

/**
 * Aplikasi staf yang bisa dipasang di HP (PWA, dan APK Trusted Web Activity setelah domain ada).
 *
 * Manifest & service worker hanya dipasang di area staf (login, absen, kasir, barista, admin).
 * Halaman pelanggan (beranda, menu/QR, status pesanan, kiosk) tidak menawarkan pemasangan.
 */
final class StaffApp
{
    /** Segmen URL pertama milik halaman pelanggan. '' = beranda. */
    private const CUSTOMER_SEGMENTS = ['', 'order', 'kiosk'];

    private const PACKAGE = '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/';

    private const FINGERPRINT = '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/';

    public static function offeredOn(string $path): bool
    {
        $first = explode('/', trim($path, '/'))[0];

        return ! in_array($first, self::CUSTOMER_SEGMENTS, true);
    }

    /**
     * Isi /.well-known/assetlinks.json agar APK membuka web tanpa bilah alamat.
     * Null bila paket/sidik jari belum diisi atau formatnya salah.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function assetLinks(): ?array
    {
        $package = trim((string) config('services.android.package'));
        $fingerprints = array_values(array_filter(array_map(
            fn (string $value) => strtoupper(trim($value)),
            explode(',', (string) config('services.android.sha256')),
        )));
        if (preg_match(self::PACKAGE, $package) !== 1 || $fingerprints === []) {
            return null;
        }
        foreach ($fingerprints as $fingerprint) {
            if (preg_match(self::FINGERPRINT, $fingerprint) !== 1) {
                return null;
            }
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]];
    }
}
