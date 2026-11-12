<?php

namespace Tests\Unit;

use App\Console\Commands\RealMenuCommand;
use Tests\TestCase;

/**
 * Data 22 menu asli Sudaya (file owner "menu coffee fix sudaya baru.docx") dan keputusan owner
 * 12 November: nama dikoreksi, deskripsi dibuat dari nama menu, Americano Series, harga menyusul.
 */
class RealMenuTest extends TestCase
{
    public function test_dua_puluh_dua_menu_urutan_sku_tetap(): void
    {
        $menu = RealMenuCommand::MENU;

        $this->assertCount(22, $menu);
        $this->assertSame(count($menu), count(array_unique(array_column($menu, 'name'))), 'nama unik');
        // SKU mengikuti urutan: jangan menyisipkan menu di tengah (menu di database dicocokkan lewat SKU).
        $this->assertSame('Americano', $menu[0]['name']);
        $this->assertSame('SDY-01', RealMenuCommand::sku(0));
        $this->assertSame(['SDY-11', 'Maren'], [RealMenuCommand::sku(10), $menu[10]['name']]);
        $this->assertSame(['SDY-12', 'Matcha Berry'], [RealMenuCommand::sku(11), $menu[11]['name']]);
        $this->assertSame(['SDY-22', 'Japanese Iced Coffee'], [RealMenuCommand::sku(21), $menu[21]['name']]);
        $this->assertSame(RealMenuCommand::CATEGORIES, array_values(array_unique(array_column($menu, 'category'))), 'kategori menu = urutan file owner');
    }

    public function test_deskripsi_dan_penyajian(): void
    {
        foreach (RealMenuCommand::MENU as $item) {
            $this->assertNotSame('', trim($item['description']), $item['name']);
            $this->assertLessThanOrEqual(200, mb_strlen($item['description']), $item['name']);
            $this->assertStringNotContainsStringIgnoringCase('contoh', $item['description'], $item['name']);
            $this->assertContains($item['serve'], ['ice_hot', 'ice', null], $item['name']);
            if ($item['serve'] === 'ice') {
                $this->assertStringContainsString('Disajikan dingin', $item['description'], $item['name']);
            }
        }
        $this->assertStringContainsString('aren', RealMenuCommand::MENU[10]['description'], 'Maren = matcha + aren');
    }

    public function test_americano_series_dan_harga_menyusul(): void
    {
        $americano = array_values(array_filter(RealMenuCommand::MENU, fn (array $item) => $item['americano'] ?? false));

        $this->assertSame(['Americano'], array_column($americano, 'name'), 'hanya satu menu Americano dengan varian');
        $this->assertSame(['Original', 'Matcha', 'Sitrus'], RealMenuCommand::AMERICANO_VARIANTS, 'Original pertama = pilihan bawaan');
        $this->assertStringContainsString('request', $americano[0]['description']);
        foreach (RealMenuCommand::MENU as $item) {
            $this->assertTrue($item['price'] === null || $item['price'] > 0, "{$item['name']}: harga null (menyusul) atau positif");
        }
    }
}
