<?php

namespace App\Services\Outlets;

use App\Exceptions\AccessException;

/** Validasi input pengaturan outlet dan daftar meja. Murni, tanpa database. */
final class OutletInput
{
    /** Format nomor meja: sama dengan CheckoutInput (huruf, angka, '-', maks 10). */
    public const TABLE_PATTERN = '/^[A-Z0-9-]{1,10}$/';

    public const MAX_TABLES_PER_REQUEST = 50;

    /** @return array{name: string, address: ?string, orderNumberPrefix: string, pendingPaymentTtlMinutes: int, isActive: bool} */
    public static function settings(array $raw): array
    {
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        if (mb_strlen($name) < 2) {
            self::fail('Nama outlet minimal 2 karakter.');
        }
        if (mb_strlen($name) > 80) {
            self::fail('Nama outlet maksimal 80 karakter.');
        }

        $address = $raw['address'] ?? null;
        if ($address !== null && ! is_string($address)) {
            self::fail('Input tidak valid.');
        }
        $address = $address === null || trim($address) === '' ? null : trim($address);
        if ($address !== null && mb_strlen($address) > 200) {
            self::fail('Alamat maksimal 200 karakter.');
        }

        $prefix = is_string($raw['orderNumberPrefix'] ?? null) ? strtoupper(trim($raw['orderNumberPrefix'])) : '';
        if (preg_match('/^[A-Z0-9]{1,6}$/', $prefix) !== 1) {
            self::fail('Prefix antrean 1–6 huruf atau angka, mis. SDY.');
        }

        $ttl = $raw['pendingPaymentTtlMinutes'] ?? null;
        if (is_string($ttl) && preg_match('/^\d+$/', trim($ttl)) === 1) {
            $ttl = (int) trim($ttl);
        }
        if (! is_int($ttl) || $ttl < 5 || $ttl > 180) {
            self::fail('Batas waktu pembayaran harus 5–180 menit.');
        }

        $isActive = $raw['isActive'] ?? null;
        if (! is_bool($isActive)) {
            self::fail('Input tidak valid.');
        }

        return ['name' => $name, 'address' => $address, 'orderNumberPrefix' => $prefix, 'pendingPaymentTtlMinutes' => $ttl, 'isActive' => $isActive];
    }

    /**
     * Daftar nomor meja dari satu isian: dipisah koma, spasi, atau baris baru. Dinormalkan
     * ke huruf besar dan dibuang duplikatnya, urutan dipertahankan.
     *
     * @return list<string>
     */
    public static function tableNumbers(mixed $raw): array
    {
        if (! is_string($raw)) {
            self::fail('Isi nomor meja.');
        }
        $numbers = array_values(array_unique(array_filter(array_map(
            fn (string $n) => strtoupper(trim($n)),
            preg_split('/[\s,;]+/', $raw) ?: [],
        ), fn (string $n) => $n !== '')));
        if ($numbers === []) {
            self::fail('Isi nomor meja.');
        }
        if (count($numbers) > self::MAX_TABLES_PER_REQUEST) {
            self::fail('Maksimal '.self::MAX_TABLES_PER_REQUEST.' meja sekali tambah.');
        }
        foreach ($numbers as $number) {
            if (preg_match(self::TABLE_PATTERN, $number) !== 1) {
                self::fail("Nomor meja \"{$number}\" tidak valid. Gunakan huruf, angka, dan '-', maks 10 karakter.");
            }
        }

        return $numbers;
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }
}
