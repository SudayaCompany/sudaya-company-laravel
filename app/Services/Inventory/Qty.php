<?php

namespace App\Services\Inventory;

use App\Exceptions\AccessException;

/**
 * Satuan & kuantitas stok, port `lib/inventory/units.ts`. Semua hitungan memakai integer
 * "milli-unit" (skala 3, sama dengan kolom numeric(14,3)), tidak pernah float. Konversi
 * hanya antar satuan berdimensi sama (g↔kg, ml↔l); satuan hitung (pcs, botol, …) tidak.
 */
final class Qty
{
    /** kode => [label, dimensi, faktor ke satuan dasar dimensi, harus bilangan bulat] */
    public const UNITS = [
        'g' => ['gram (g)', 'mass', 1, false],
        'kg' => ['kilogram (kg)', 'mass', 1000, false],
        'ml' => ['mililiter (ml)', 'volume', 1, false],
        'l' => ['liter (l)', 'volume', 1000, false],
        'pcs' => ['pcs', 'pcs', 1, true],
        'botol' => ['botol', 'botol', 1, true],
        'cup' => ['cup', 'cup', 1, true],
        'pack' => ['pack', 'pack', 1, true],
        'sachet' => ['sachet', 'sachet', 1, true],
        'box' => ['box', 'box', 1, true],
        'lembar' => ['lembar', 'lembar', 1, true],
    ];

    /** Batas jumlah satu input (milli-unit): 1 juta satuan. */
    private const MAX_MILLI = 1_000_000_000;

    public static function isUnit(mixed $unit): bool
    {
        return is_string($unit) && array_key_exists($unit, self::UNITS);
    }

    /** @return list<string> satuan input yang setara dengan satuan dasar item */
    public static function compatible(string $base): array
    {
        $dimension = self::UNITS[$base][1] ?? null;

        return array_keys(array_filter(self::UNITS, fn ($u) => $u[1] === $dimension));
    }

    /**
     * Teks jumlah ("1,5" atau "1.5") dalam satuan $unit → milli-unit satuan dasar $base.
     * Hasil harus > 0, tepat (tanpa pembulatan diam-diam), dan bulat untuk satuan hitung.
     */
    public static function parse(mixed $value, mixed $unit, string $base): int
    {
        if (! self::isUnit($unit) || ! in_array($unit, self::compatible($base), true)) {
            throw new AccessException('invalid', 'Satuan tidak sesuai dengan item.');
        }
        $text = is_int($value) ? (string) $value : (is_string($value) ? str_replace(',', '.', trim($value)) : '');
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $text)) {
            throw new AccessException('invalid', 'Jumlah tidak valid (maksimal 3 angka di belakang koma).');
        }
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');
        $milliInput = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        // Faktor satuan selalu 1 atau 1000: hitung dengan integer, tanpa float.
        [$from, $to] = [self::UNITS[$unit][2], self::UNITS[$base][2]];
        if ($from >= $to) {
            $milli = $milliInput * intdiv($from, $to);
        } else {
            $divisor = intdiv($to, $from);
            if ($milliInput % $divisor !== 0) {
                throw new AccessException('invalid', 'Jumlah terlalu kecil untuk satuan item.');
            }
            $milli = intdiv($milliInput, $divisor);
        }
        if ($milli <= 0) {
            throw new AccessException('invalid', 'Jumlah harus lebih dari 0.');
        }
        if ($milli > self::MAX_MILLI) {
            throw new AccessException('invalid', 'Jumlah terlalu besar.');
        }
        if (self::UNITS[$base][3] && $milli % 1000 !== 0) {
            throw new AccessException('invalid', "Jumlah {$base} harus bilangan bulat.");
        }

        return $milli;
    }

    /** Hasil hitung fisik (opname): sama dengan parse, tetapi 0 diperbolehkan. */
    public static function parseCount(mixed $value, string $unit): int
    {
        $text = is_string($value) ? str_replace(',', '.', trim($value)) : (is_int($value) ? (string) $value : '');
        if (preg_match('/^0+(\.0+)?$/', $text)) {
            return 0;
        }

        return self::parse($text, $unit, $unit);
    }

    /** Teks numeric dari DB ("12.500") → milli-unit. */
    public static function fromDb(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        $text = (string) $value;
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');
        $milli = (int) $whole * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$milli : $milli;
    }

    /** milli-unit → teks numeric untuk DB ("-12.500"). */
    public static function toDb(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);

        return $sign.intdiv($abs, 1000).'.'.str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** milli-unit → "12,5 kg" (format Indonesia, tanpa nol di belakang). */
    public static function format(int $milli, string $unit): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);
        $fraction = rtrim(str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT), '0');
        $whole = number_format(intdiv($abs, 1000), 0, ',', '.');

        return $sign.$whole.($fraction === '' ? '' : ','.$fraction).' '.$unit;
    }

    /** Harga per satuan input → per satuan dasar (numeric skala 4), null bila kosong. */
    public static function unitCost(mixed $value, string $unit, string $base): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim(str_replace('.', '', $value)) : '');
        if (! preg_match('/^\d{1,12}$/', $text)) {
            throw new AccessException('invalid', 'Harga beli harus angka rupiah bulat.');
        }
        $factor = self::UNITS[$unit][2] / self::UNITS[$base][2];

        return number_format((int) $text / $factor, 4, '.', '');
    }
}
