<?php

namespace App\Support;

use App\Exceptions\AccessException;

/** Nominal Rupiah bulat (tanpa sen), padanan formatRupiah dan validator jumlah Zod. */
final class Money
{
    /** 5000 → "Rp 5.000", -5000 → "-Rp 5.000" (sama dengan formatRupiah di browser). */
    public static function rupiah(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }

    /** Angka bulat dari input form (angka atau teks angka). Pesan sama dengan Next.js. */
    public static function amount(mixed $value, string $label, int $min = 0, int $max = 1_000_000_000, ?string $minMessage = null): int
    {
        $text = is_string($value) ? trim($value) : $value;
        if (! is_int($text) && ! is_float($text) && ! (is_string($text) && $text !== '' && is_numeric($text))) {
            self::fail("{$label} harus angka.");
        }
        $number = (float) $text;
        if (is_string($text) ? preg_match('/^-?\d+$/', $text) !== 1 : floor($number) !== $number) {
            self::fail("{$label} harus bilangan bulat.");
        }
        if ($number < 0) {
            self::fail("{$label} tidak boleh negatif.");
        }
        if ($number < $min) {
            self::fail($minMessage ?? "{$label} minimal {$min}.");
        }
        if ($number > $max) {
            self::fail("{$label} terlalu besar.");
        }

        return (int) $number;
    }

    /** Teks alasan wajib (min 3 karakter). */
    public static function reason(mixed $value, int $max, string $requiredMessage = 'Alasan wajib diisi.', ?string $shortMessage = null): string
    {
        if (! is_string($value)) {
            self::fail($requiredMessage);
        }
        $value = trim($value);
        if (mb_strlen($value) < 3) {
            self::fail($shortMessage ?? $requiredMessage);
        }
        if (mb_strlen($value) > $max) {
            self::fail("Alasan maksimal {$max} karakter.");
        }

        return $value;
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }
}
