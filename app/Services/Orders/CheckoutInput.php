<?php

namespace App\Services\Orders;

use App\Exceptions\AccessException;
use App\Services\Catalog\CatalogInput;
use App\Support\Catalog\Pricing;

/**
 * Input checkout untuk semua channel, padanan lib/orders/schemas.ts. Hanya id,
 * jumlah, catatan, dan data customer yang diterima dari client: harga dan total
 * dihitung server. Pesan error sama dengan Next.js.
 */
final class CheckoutInput
{
    public const PUBLIC_CHANNELS = ['qr_table', 'qr_counter', 'website', 'kiosk'];

    public const TYPES = ['dine_in', 'takeaway', 'delivery'];

    /** "+62 812-3456-7890" / "6281234567890" → "081234567890" */
    public static function normalizePhone(string $value): string
    {
        $digits = (string) preg_replace('/[^\d+]/', '', $value);
        if (str_starts_with($digits, '+62')) {
            return '0'.substr($digits, 3);
        }
        if (str_starts_with($digits, '62')) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * @return array{idempotencyKey: string, type: string, customerName: ?string, customerPhone: ?string, tableNumber: ?string, locationNote: ?string, deliveryAddress: ?string, notes: ?string, paymentMethod: string, lines: list<array{productId: string, modifierIds: list<string>, quantity: int, notes: ?string}>}
     */
    public static function parse(mixed $raw): array
    {
        if (! is_array($raw)) {
            self::fail('Input tidak valid.');
        }
        if (! CatalogInput::isUuid($raw['idempotencyKey'] ?? null)) {
            self::fail('Permintaan tidak valid. Muat ulang halaman.');
        }
        $type = $raw['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            self::fail('Pilih tipe pesanan.');
        }

        $customerName = self::optionalText($raw['customerName'] ?? null, 60, 'Nama');
        $customerPhone = self::optionalText($raw['customerPhone'] ?? null, 40, 'Nomor HP');
        if ($customerPhone !== null) {
            $customerPhone = self::normalizePhone($customerPhone);
            if (preg_match('/^08\d{7,12}$/', $customerPhone) !== 1) {
                self::fail('Nomor HP tidak valid (contoh 0812xxxxxxx).');
            }
        }
        $tableNumber = self::optionalText($raw['tableNumber'] ?? null, 10, 'Nomor meja');
        if ($tableNumber !== null && preg_match('/^[A-Za-z0-9-]*$/', $tableNumber) !== 1) {
            self::fail("Nomor meja hanya huruf, angka, dan '-'.");
        }
        $tableNumber = $tableNumber === null ? null : strtoupper($tableNumber);
        $locationNote = self::optionalText($raw['locationNote'] ?? null, 100, 'Lokasi/keterangan');
        $deliveryAddress = self::optionalText($raw['deliveryAddress'] ?? null, 300, 'Alamat');
        $notes = self::optionalText($raw['notes'] ?? null, 300, 'Catatan pesanan');
        $paymentMethod = $raw['paymentMethod'] ?? null;
        if (! in_array($paymentMethod, ['cash', 'qris'], true)) {
            self::fail('Pilih metode pembayaran.');
        }

        $rawLines = $raw['lines'] ?? null;
        if (! is_array($rawLines) || ! array_is_list($rawLines)) {
            self::fail('Input tidak valid.');
        }
        if ($rawLines === []) {
            self::fail('Keranjang masih kosong.');
        }
        if (count($rawLines) > 50) {
            self::fail('Maksimal 50 item per pesanan.');
        }
        $lines = array_map(fn ($line) => self::line($line), $rawLines);

        if ($type === 'delivery') {
            if ($customerPhone === null) {
                self::fail('Nomor HP wajib untuk delivery.');
            }
            if ($deliveryAddress === null || mb_strlen($deliveryAddress) < 10) {
                self::fail('Alamat delivery wajib diisi lengkap (min. 10 karakter).');
            }
            if ($customerName === null || mb_strlen($customerName) < 2) {
                self::fail('Nama wajib untuk delivery (min. 2 karakter).');
            }
        }

        return [
            'idempotencyKey' => strtolower($raw['idempotencyKey']),
            'type' => $type,
            'customerName' => $customerName,
            'customerPhone' => $customerPhone,
            'tableNumber' => $tableNumber,
            'locationNote' => $locationNote,
            'deliveryAddress' => $deliveryAddress,
            'notes' => $notes,
            'paymentMethod' => $paymentMethod,
            'lines' => $lines,
        ];
    }

    /** Channel publik (QR, web, kiosk) wajib mengisi nama pemesan. */
    public static function requireCustomerName(array $input): ?string
    {
        return $input['customerName'] === null || mb_strlen($input['customerName']) < 2
            ? 'Nama pemesan wajib diisi (min. 2 karakter).'
            : null;
    }

    /**
     * Sidik isi checkout, tanpa idempotency key dan tanpa harga: retry setelah harga
     * berubah tetap dianggap request yang sama. Hanya field yang disimpan untuk tipe
     * pesanan tsb yang ikut. Algoritma sama dengan lib/orders/request-hash.ts.
     */
    public static function requestHash(array $input): string
    {
        $canonical = [
            $input['type'],
            $input['customerName'],
            $input['customerPhone'],
            $input['type'] === 'dine_in' ? $input['tableNumber'] : null,
            $input['type'] === 'dine_in' ? $input['locationNote'] : null,
            $input['type'] === 'delivery' ? $input['deliveryAddress'] : null,
            $input['notes'],
            $input['paymentMethod'],
            array_map(function (array $line) {
                $modifiers = $line['modifierIds'];
                sort($modifiers, SORT_STRING);

                return [$line['productId'], $modifiers, $line['quantity'], $line['notes']];
            }, $input['lines']),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    /** @return array{productId: string, modifierIds: list<string>, quantity: int, notes: ?string} */
    private static function line(mixed $raw): array
    {
        if (! is_array($raw) || ! CatalogInput::isUuid($raw['productId'] ?? null)) {
            self::fail('Input tidak valid.');
        }
        $modifierIds = $raw['modifierIds'] ?? null;
        if (! is_array($modifierIds) || ! array_is_list($modifierIds) || count($modifierIds) > 30) {
            self::fail('Input tidak valid.');
        }
        foreach ($modifierIds as $id) {
            if (! CatalogInput::isUuid($id)) {
                self::fail('Input tidak valid.');
            }
        }
        $quantity = $raw['quantity'] ?? null;
        if (! is_int($quantity) || $quantity < 1 || $quantity > Pricing::MAX_LINE_QUANTITY) {
            self::fail('Input tidak valid.');
        }

        return [
            'productId' => strtolower($raw['productId']),
            'modifierIds' => array_map('strtolower', $modifierIds),
            'quantity' => $quantity,
            'notes' => self::optionalText($raw['notes'] ?? null, 140, 'Catatan item'),
        ];
    }

    private static function optionalText(mixed $value, int $max, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            self::fail('Input tidak valid.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            self::fail("{$label} maksimal {$max} karakter.");
        }

        return $value === '' ? null : $value;
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }
}
