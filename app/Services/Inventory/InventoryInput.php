<?php

namespace App\Services\Inventory;

use App\Exceptions\AccessException;
use App\Services\Catalog\CatalogInput;

/** Validasi input stok. Semua nilai dari browser dianggap tidak tepercaya. */
final class InventoryInput
{
    /**
     * @return array{sku: string, name: string, kind: string, category: ?string, unit: string, minimum: int, purchasePrice: ?string, expiryDate: ?string, isActive: bool}
     */
    public static function item(array $raw, bool $create, ?string $currentUnit = null): array
    {
        $text = fn ($v) => is_string($v) ? trim($v) : '';
        $sku = strtoupper($text($raw['sku'] ?? null));
        if (! preg_match('/^[A-Z0-9._-]{2,32}$/', $sku)) {
            self::fail('SKU 2–32 karakter: huruf, angka, titik, minus, atau garis bawah.');
        }
        $name = $text($raw['name'] ?? null);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            self::fail('Nama item 2–80 karakter.');
        }
        $kind = $raw['kind'] ?? null;
        if (! array_key_exists((string) $kind, InventoryAdmin::KINDS)) {
            self::fail('Pilih jenis item.');
        }
        $category = $text($raw['category'] ?? null);
        if (mb_strlen($category) > 40) {
            self::fail('Kategori maksimal 40 karakter.');
        }
        $unit = $raw['unit'] ?? $currentUnit;
        if (! Qty::isUnit($unit)) {
            self::fail('Pilih satuan stok.');
        }
        $minimumText = $text((string) ($raw['minimum'] ?? ''));
        $minimum = $minimumText === '' || preg_match('/^0+([.,]0+)?$/', $minimumText) ? 0 : Qty::parse($minimumText, $unit, $unit);

        return [
            'sku' => $sku,
            'name' => $name,
            'kind' => $kind,
            'category' => $category === '' ? null : $category,
            'unit' => $unit,
            'minimum' => $minimum,
            'purchasePrice' => Qty::unitCost($raw['purchasePrice'] ?? null, $unit, $unit),
            'expiryDate' => self::date($raw['expiryDate'] ?? null, 'Tanggal kedaluwarsa'),
            'isActive' => $create ? true : CatalogInput::boolean($raw['isActive'] ?? true),
        ];
    }

    /** @return list<string> */
    public static function uuids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            $ids[] = CatalogInput::uuid($id);
        }

        return array_values(array_unique($ids));
    }

    public static function date(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            self::fail("{$label} tidak valid.");
        }

        return $value;
    }

    /** Kunci dari browser (UUID per pengisian form) agar kirim ganda tidak tercatat dua kali. */
    public static function idempotencyKey(mixed $value): string
    {
        if (! CatalogInput::isUuid($value)) {
            self::fail('Form kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        }

        return strtolower($value);
    }

    public static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }
}
