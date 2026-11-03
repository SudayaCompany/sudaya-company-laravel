<?php

namespace App\Services\Catalog;

use App\Exceptions\AccessException;
use App\Support\Catalog\Slug;

/**
 * Validasi dan normalisasi input katalog, padanan lib/catalog/schemas.ts (Zod).
 * Hanya field yang didefinisikan di sini yang diterima dari client (anti mass
 * assignment): company, brand, image_path, dan harga efektif selalu dari server.
 * Pesan error sama dengan Next.js. Pesan pertama yang gagal dilempar sebagai
 * AccessException 'invalid'.
 */
final class CatalogInput
{
    public const MAX_PRICE = 10_000_000;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @return array{name: string, slug: string, sku: ?string, categoryId: string, description: ?string, basePrice: int, isActive: bool, modifierGroupIds: list<string>} */
    public static function product(array $raw): array
    {
        $name = self::name($raw['name'] ?? null, 2, 120);
        $slug = self::slug($raw['slug'] ?? null, $name, 'Slug tidak bisa dibuat dari nama ini. Isi slug secara manual.');

        $sku = self::optionalString($raw['sku'] ?? null);
        if ($sku !== null) {
            $sku = strtoupper($sku);
            if (mb_strlen($sku) > 32) {
                self::fail('SKU maksimal 32 karakter.');
            }
            if (preg_match('/^[A-Z0-9][A-Z0-9-]*$/', $sku) !== 1) {
                self::fail('SKU hanya huruf, angka, dan tanda hubung.');
            }
        }

        $description = self::optionalString($raw['description'] ?? null);
        if ($description !== null && mb_strlen($description) > 1000) {
            self::fail('Deskripsi maksimal 1000 karakter.');
        }

        $groupIds = $raw['modifierGroupIds'] ?? [];
        if (! is_array($groupIds) || ! array_is_list($groupIds)) {
            self::fail('Input tidak valid.');
        }
        foreach ($groupIds as $id) {
            self::uuid($id);
        }
        if (count($groupIds) > 20) {
            self::fail('Maksimal 20 grup modifier.');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'sku' => $sku,
            'categoryId' => self::uuid($raw['categoryId'] ?? null),
            'description' => $description,
            'basePrice' => self::integer($raw['basePrice'] ?? null, 'Harga', 0, self::MAX_PRICE, true),
            'isActive' => self::boolean($raw['isActive'] ?? null),
            'modifierGroupIds' => array_values(array_unique(array_map('strtolower', $groupIds))),
        ];
    }

    /** @return array{name: string, slug: string, sortOrder: int, isActive: bool} */
    public static function category(array $raw): array
    {
        $name = self::name($raw['name'] ?? null, 2, 60);

        return [
            'name' => $name,
            'slug' => self::slug($raw['slug'] ?? null, $name, 'Slug tidak bisa dibuat dari nama ini.'),
            'sortOrder' => self::integer($raw['sortOrder'] ?? 0, 'Urutan', 0, 9999),
            'isActive' => array_key_exists('isActive', $raw) ? self::boolean($raw['isActive']) : true,
        ];
    }

    /** @return array{name: string, selection: string, isRequired: bool, minSelect: int, maxSelect: ?int, sortOrder: int, options: list<array{id: ?string, name: string, priceDelta: int, isDefault: bool, isActive: bool}>} */
    public static function modifierGroup(array $raw): array
    {
        $name = self::name($raw['name'] ?? null, 2, 60);
        $selection = $raw['selection'] ?? null;
        if (! in_array($selection, ['single', 'multiple'], true)) {
            self::fail('Input tidak valid.');
        }
        $isRequired = self::boolean($raw['isRequired'] ?? null);
        $minSelect = self::integer($raw['minSelect'] ?? null, 'Minimal pilihan', 0, 20);
        $maxSelect = ($raw['maxSelect'] ?? null) === null ? null : self::integer($raw['maxSelect'], 'Maksimal pilihan', 1, 20);
        $sortOrder = self::integer($raw['sortOrder'] ?? 0, 'Urutan', 0, 9999);

        $rawOptions = $raw['options'] ?? null;
        if (! is_array($rawOptions) || ! array_is_list($rawOptions)) {
            self::fail('Input tidak valid.');
        }
        $options = array_map(fn ($option) => self::modifierOption(is_array($option) ? $option : []), $rawOptions);
        if (count($options) < 1) {
            self::fail('Tambahkan minimal satu pilihan.');
        }
        if (count($options) > 30) {
            self::fail('Maksimal 30 pilihan per grup.');
        }

        // Normalisasi aturan pilihan agar konsisten.
        $maxSelect = $selection === 'single' ? 1 : $maxSelect;
        $minSelect = $isRequired ? max($minSelect, 1) : $minSelect;
        if ($maxSelect !== null && $minSelect > $maxSelect) {
            self::fail('Minimal pilihan tidak boleh melebihi maksimal.');
        }
        if ($minSelect > count(array_filter($options, fn ($o) => $o['isActive']))) {
            self::fail('Minimal pilihan melebihi jumlah opsi aktif.');
        }
        if ($selection === 'single' && count(array_filter($options, fn ($o) => $o['isDefault'])) > 1) {
            self::fail('Grup pilihan tunggal hanya boleh punya satu default.');
        }

        return compact('name', 'selection', 'isRequired', 'minSelect', 'maxSelect', 'sortOrder', 'options');
    }

    /** @return array{id: ?string, name: string, priceDelta: int, isDefault: bool, isActive: bool} */
    private static function modifierOption(array $raw): array
    {
        $priceDelta = $raw['priceDelta'] ?? null;
        if (! self::isNumeric($priceDelta)) {
            self::fail('Selisih harga harus angka.');
        }
        if (! self::isWhole($priceDelta)) {
            self::fail('Selisih harga harus bilangan bulat.');
        }
        $priceDelta = (int) $priceDelta;
        if ($priceDelta < -1_000_000 || $priceDelta > 1_000_000) {
            self::fail('Input tidak valid.');
        }

        return [
            'id' => ($raw['id'] ?? null) === null ? null : self::uuid($raw['id']),
            'name' => self::name($raw['name'] ?? null, 1, 60),
            'priceDelta' => $priceDelta,
            'isDefault' => array_key_exists('isDefault', $raw) ? self::boolean($raw['isDefault']) : false,
            'isActive' => array_key_exists('isActive', $raw) ? self::boolean($raw['isActive']) : true,
        ];
    }

    /** Parameter daftar produk. Nilai tidak valid jatuh ke default, sama seperti `.catch()` Zod. */
    public static function productListParams(array $raw): array
    {
        $pick = fn (string $key, array $allowed, string $default) => in_array($raw[$key] ?? null, $allowed, true) ? $raw[$key] : $default;
        $q = is_string($raw['q'] ?? null) ? trim($raw['q']) : '';
        $page = filter_var($raw['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10_000]]);

        return [
            'q' => $q === '' || mb_strlen($q) > 100 ? null : $q,
            'category' => self::isUuid($raw['category'] ?? null) ? strtolower($raw['category']) : null,
            'status' => $pick('status', ['all', 'active', 'inactive'], 'all'),
            'availability' => $pick('availability', ['all', 'available', 'sold_out'], 'all'),
            'sort' => $pick('sort', ['name', 'price', 'updated'], 'name'),
            'dir' => $pick('dir', ['asc', 'desc'], 'asc'),
            'page' => $page === false ? 1 : $page,
            'outlet' => self::isUuid($raw['outlet'] ?? null) ? strtolower($raw['outlet']) : null,
        ];
    }

    public static function uuid(mixed $value): string
    {
        if (! self::isUuid($value)) {
            self::fail('ID tidak valid.');
        }

        return strtolower($value);
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    public static function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            self::fail('Input tidak valid.');
        }

        return $value;
    }

    private static function name(mixed $value, int $min, int $max): string
    {
        if (! is_string($value)) {
            self::fail('Nama wajib diisi.');
        }
        $value = trim($value);
        if (mb_strlen($value) < $min) {
            self::fail("Nama minimal {$min} karakter.");
        }
        if (mb_strlen($value) > $max) {
            self::fail("Nama maksimal {$max} karakter.");
        }

        return $value;
    }

    /** Slug opsional: kosong berarti dibuat dari nama. */
    private static function slug(mixed $value, string $name, string $emptyMessage): string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if (mb_strlen($slug) > 80) {
            self::fail('Slug maksimal 80 karakter.');
        }
        if ($slug !== '' && preg_match(Slug::PATTERN, $slug) !== 1) {
            self::fail('Slug hanya huruf kecil, angka, dan tanda hubung.');
        }
        $slug = $slug !== '' ? $slug : Slug::make($name);
        if ($slug === '') {
            self::fail($emptyMessage);
        }

        return $slug;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            self::fail('Input tidak valid.');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function integer(mixed $value, string $label, int $min, int $max, bool $money = false): int
    {
        if (! self::isNumeric($value)) {
            self::fail("{$label} harus berupa angka.");
        }
        if (! self::isWhole($value)) {
            self::fail($money ? "{$label} harus bilangan bulat (Rupiah)." : "{$label} harus bilangan bulat.");
        }
        $value = (int) $value;
        if ($value < $min) {
            self::fail("{$label} minimal {$min}.");
        }
        if ($value > $max) {
            self::fail("{$label} maksimal ".number_format($max, 0, ',', '.').'.');
        }

        return $value;
    }

    private static function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }

    private static function isWhole(mixed $value): bool
    {
        return is_int($value) || (float) $value === floor((float) $value) && abs((float) $value) < PHP_INT_MAX;
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }
}
