<?php

namespace App\Support\Catalog;

/**
 * Perhitungan harga dan validasi pilihan modifier. Padanan lib/catalog/pricing.ts,
 * yang juga dipakai browser untuk tampilan. Di server ini sumber harga yang sah:
 * harga dari browser tidak pernah dipercaya.
 *
 * Produk: ['id', 'name', 'price', 'isAvailable', 'stockLimit'?, 'groups' => [...]].
 * Grup: ['id', 'name', 'selection', 'isRequired', 'minSelect', 'maxSelect', 'modifiers' => [['id', 'name', 'priceDelta']]].
 */
final class Pricing
{
    /** Batas jumlah per baris keranjang. */
    public const MAX_LINE_QUANTITY = 50;

    /** @return array{min: int, max: int} batas pilihan efektif sebuah grup */
    public static function selectionLimits(array $group): array
    {
        $optionCount = count($group['modifiers']);
        $max = $group['selection'] === 'single' ? 1 : min($group['maxSelect'] ?? $optionCount, $optionCount);
        $min = min(max($group['minSelect'], $group['isRequired'] ? 1 : 0), $max);

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param  list<string>  $selectedModifierIds
     * @return array{ok: true, unitPrice: int, modifiers: list<array<string, mixed>>}|array{ok: false, error: string}
     */
    public static function priceSelection(array $product, array $selectedModifierIds): array
    {
        if (! $product['isAvailable']) {
            return ['ok' => false, 'error' => 'Maaf, produk ini baru saja tidak tersedia.'];
        }

        $selected = array_flip(array_values(array_unique($selectedModifierIds)));
        $modifiers = [];
        $matched = 0;

        foreach ($product['groups'] as $group) {
            $picked = array_values(array_filter($group['modifiers'], fn (array $m) => isset($selected[$m['id']])));
            $matched += count($picked);
            ['min' => $min, 'max' => $max] = self::selectionLimits($group);
            if (count($picked) < $min) {
                return ['ok' => false, 'error' => $min === 1 ? "Pilih {$group['name']}." : "Pilih minimal {$min} {$group['name']}."];
            }
            if (count($picked) > $max) {
                return ['ok' => false, 'error' => "Maksimal {$max} pilihan untuk {$group['name']}."];
            }
            foreach ($picked as $modifier) {
                $modifiers[] = [
                    'groupId' => $group['id'],
                    'groupName' => $group['name'],
                    'modifierId' => $modifier['id'],
                    'name' => $modifier['name'],
                    'priceDelta' => $modifier['priceDelta'],
                ];
            }
        }

        // Id yang bukan milik produk ini (mis. dimanipulasi) ditolak.
        if ($matched !== count($selected)) {
            return ['ok' => false, 'error' => 'Pilihan tidak valid untuk produk ini.'];
        }

        $unitPrice = array_reduce($modifiers, fn (int $sum, array $m) => $sum + $m['priceDelta'], $product['price']);

        return ['ok' => true, 'unitPrice' => max($unitPrice, 0), 'modifiers' => $modifiers];
    }
}
