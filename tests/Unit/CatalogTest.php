<?php

namespace Tests\Unit;

use App\Exceptions\AccessException;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Services\Catalog\Storefront;
use App\Support\Catalog\Pricing;
use App\Support\Catalog\Slug;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Padanan tests/unit/catalog.test.ts. */
class CatalogTest extends TestCase
{
    private static function id(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private static function latte(): array
    {
        return [
            'id' => self::id(1),
            'name' => 'Iced Latte',
            'price' => 25000,
            'isAvailable' => true,
            'groups' => [
                [
                    'id' => self::id(10), 'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
                    'modifiers' => [
                        ['id' => self::id(11), 'name' => 'Regular', 'priceDelta' => 0],
                        ['id' => self::id(12), 'name' => 'Large', 'priceDelta' => 6000],
                    ],
                ],
                [
                    'id' => self::id(20), 'name' => 'Topping', 'selection' => 'multiple', 'isRequired' => false, 'minSelect' => 0, 'maxSelect' => 2,
                    'modifiers' => [
                        ['id' => self::id(21), 'name' => 'Extra Shot', 'priceDelta' => 5000],
                        ['id' => self::id(22), 'name' => 'Hazelnut Syrup', 'priceDelta' => 3000],
                        ['id' => self::id(23), 'name' => 'Cream', 'priceDelta' => 0],
                    ],
                ],
            ],
        ];
    }

    private function expectInvalid(callable $call, ?string $message = null): void
    {
        try {
            $call();
            $this->fail('input diterima');
        } catch (AccessException $error) {
            $this->assertSame('invalid', $error->reason);
            if ($message !== null) {
                $this->assertSame($message, $error->getMessage());
            }
        }
    }

    // --- pricing -------------------------------------------------------------------

    public function test_menghitung_harga_dasar_ditambah_selisih_modifier(): void
    {
        $result = Pricing::priceSelection(self::latte(), [self::id(12), self::id(21), self::id(22)]);
        $this->assertTrue($result['ok']);
        $this->assertSame(25000 + 6000 + 5000 + 3000, $result['unitPrice']);
    }

    public function test_menolak_grup_wajib_yang_belum_dipilih(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'Pilih Size.'], Pricing::priceSelection(self::latte(), []));
    }

    public function test_menolak_pilihan_melebihi_maksimal(): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => 'Maksimal 2 pilihan untuk Topping.'],
            Pricing::priceSelection(self::latte(), [self::id(11), self::id(21), self::id(22), self::id(23)]),
        );
    }

    public function test_menolak_dua_pilihan_di_grup_single(): void
    {
        $this->assertFalse(Pricing::priceSelection(self::latte(), [self::id(11), self::id(12)])['ok']);
    }

    public function test_menolak_id_modifier_yang_bukan_milik_produk(): void
    {
        $this->assertSame(
            ['ok' => false, 'error' => 'Pilihan tidak valid untuk produk ini.'],
            Pricing::priceSelection(self::latte(), [self::id(11), self::id(999)]),
        );
    }

    public function test_menolak_produk_sold_out(): void
    {
        $this->assertFalse(Pricing::priceSelection([...self::latte(), 'isAvailable' => false], [self::id(11)])['ok']);
    }

    public function test_harga_tidak_pernah_negatif(): void
    {
        $latte = self::latte();
        $discount = [...$latte, 'price' => 1000, 'groups' => [[...$latte['groups'][1], 'modifiers' => [['id' => self::id(30), 'name' => 'Promo', 'priceDelta' => -5000]]]]];
        $this->assertSame(0, Pricing::priceSelection($discount, [self::id(30)])['unitPrice']);
    }

    public function test_batas_pilihan_efektif(): void
    {
        $latte = self::latte();
        $this->assertSame(['min' => 1, 'max' => 1], Pricing::selectionLimits($latte['groups'][0]));
        $this->assertSame(['min' => 0, 'max' => 3], Pricing::selectionLimits([...$latte['groups'][1], 'maxSelect' => null]));
    }

    // --- quote ---------------------------------------------------------------------

    private static function storefront(): array
    {
        return ['products' => [[...self::latte(), 'slug' => 'iced-latte']]];
    }

    public function test_harga_kiriman_browser_diabaikan_dan_dihitung_ulang(): void
    {
        $input = Storefront::quoteInput([
            'outlet' => 'SDY01',
            'lines' => [['productId' => self::id(1), 'modifierIds' => [self::id(12)], 'quantity' => 2, 'unitPrice' => 1, 'total' => 1]],
        ]);
        $this->assertArrayNotHasKey('unitPrice', $input['lines'][0]);
        $quote = Storefront::quote(self::storefront(), $input['lines']);
        $this->assertSame((25000 + 6000) * 2, $quote['total']);
        $this->assertSame(2, $quote['itemCount']);
    }

    public function test_produk_tidak_dikenal_ditandai_error_dan_tidak_dihitung(): void
    {
        $quote = Storefront::quote(self::storefront(), [['productId' => self::id(2), 'modifierIds' => [], 'quantity' => 1]]);
        $this->assertSame(0, $quote['total']);
        $this->assertFalse($quote['lines'][0]['ok']);
    }

    public function test_jumlah_dibatasi_1_sampai_50_dan_harus_bilangan_bulat(): void
    {
        $line = ['productId' => self::id(1), 'modifierIds' => []];
        foreach ([0, 51, 1.5, '2'] as $quantity) {
            $this->assertNull(Storefront::quoteInput(['outlet' => 'SDY01', 'lines' => [[...$line, 'quantity' => $quantity]]]), (string) $quantity);
        }
        $this->assertNotNull(Storefront::quoteInput(['outlet' => 'SDY01', 'lines' => [[...$line, 'quantity' => 50]]]));
    }

    public function test_stok_produk_jadi_membatasi_jumlah_seluruh_baris(): void
    {
        $storefront = ['products' => [[...self::latte(), 'stockLimit' => 3]]];
        $quote = Storefront::quote($storefront, [
            ['productId' => self::id(1), 'modifierIds' => [self::id(11)], 'quantity' => 2],
            ['productId' => self::id(1), 'modifierIds' => [self::id(12)], 'quantity' => 2],
        ]);
        $this->assertSame('Stok tersisa 3. Kurangi jumlahnya.', $quote['lines'][0]['error']);
    }

    // --- validasi input ------------------------------------------------------------

    private static function base(): array
    {
        return ['name' => 'Kopi Susu Gula Aren', 'categoryId' => self::id(5), 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => [self::id(10), self::id(10)]];
    }

    public function test_slug_dari_nama_sku_dinormalisasi_grup_unik(): void
    {
        $result = CatalogInput::product([...self::base(), 'sku' => 'ks-aren']);
        $this->assertSame('kopi-susu-gula-aren', $result['slug']);
        $this->assertSame('KS-AREN', $result['sku']);
        $this->assertSame(25000, $result['basePrice']);
        $this->assertSame([self::id(10)], $result['modifierGroupIds']);
    }

    public function test_field_di_luar_skema_dibuang(): void
    {
        $result = CatalogInput::product([...self::base(), 'companyId' => self::id(9), 'brandId' => self::id(9), 'imagePath' => 'x', 'deletedAt' => null]);
        $this->assertArrayNotHasKey('companyId', $result);
        $this->assertArrayNotHasKey('brandId', $result);
        $this->assertArrayNotHasKey('imagePath', $result);
        $this->assertArrayNotHasKey('deletedAt', $result);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPrices(): iterable
    {
        yield 'negatif' => ['-1', 'Harga minimal 0.'];
        yield 'desimal' => ['1500.5', 'Harga harus bilangan bulat (Rupiah).'];
        yield 'terlalu besar' => ['10000001', 'Harga maksimal 10.000.000.'];
        yield 'bukan angka' => ['abc', 'Harga harus berupa angka.'];
    }

    #[DataProvider('invalidPrices')]
    public function test_harga_tidak_valid_ditolak(string $price, string $message): void
    {
        $this->expectInvalid(fn () => CatalogInput::product([...self::base(), 'basePrice' => $price]), $message);
    }

    public function test_slug_dan_sku_tidak_valid_ditolak(): void
    {
        $this->expectInvalid(fn () => CatalogInput::product([...self::base(), 'slug' => 'Bukan Slug!']), 'Slug hanya huruf kecil, angka, dan tanda hubung.');
        $this->expectInvalid(fn () => CatalogInput::product([...self::base(), 'sku' => 'SKU SPASI']), 'SKU hanya huruf, angka, dan tanda hubung.');
        $this->expectInvalid(fn () => CatalogInput::product([...self::base(), 'name' => '!!!']), 'Slug tidak bisa dibuat dari nama ini. Isi slug secara manual.');
    }

    public function test_kategori_slug_otomatis(): void
    {
        $this->assertSame('non-coffee', CatalogInput::category(['name' => 'Non Coffee'])['slug']);
    }

    public function test_grup_modifier_single_dipaksa_max_1_wajib_dipaksa_min_1(): void
    {
        $result = CatalogInput::modifierGroup([
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 0, 'maxSelect' => 5,
            'options' => [['name' => 'Regular', 'priceDelta' => 0]],
        ]);
        $this->assertSame(1, $result['maxSelect']);
        $this->assertSame(1, $result['minSelect']);
    }

    public function test_grup_modifier_tidak_konsisten_ditolak(): void
    {
        $group = [
            'name' => 'Topping', 'selection' => 'multiple', 'isRequired' => false, 'minSelect' => 3, 'maxSelect' => 2,
            'options' => [['name' => 'A', 'priceDelta' => 0], ['name' => 'B', 'priceDelta' => 0]],
        ];
        $this->expectInvalid(fn () => CatalogInput::modifierGroup($group), 'Minimal pilihan tidak boleh melebihi maksimal.');
        $this->expectInvalid(fn () => CatalogInput::modifierGroup([...$group, 'maxSelect' => 5]), 'Minimal pilihan melebihi jumlah opsi aktif.');
        $this->expectInvalid(fn () => CatalogInput::modifierGroup([...$group, 'minSelect' => 0, 'options' => []]), 'Tambahkan minimal satu pilihan.');
        $this->expectInvalid(fn () => CatalogInput::modifierGroup([
            ...$group, 'selection' => 'single', 'minSelect' => 0,
            'options' => [['name' => 'A', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'B', 'priceDelta' => 0, 'isDefault' => true]],
        ]), 'Grup pilihan tunggal hanya boleh punya satu default.');
        $this->expectInvalid(fn () => CatalogInput::modifierGroup([...$group, 'minSelect' => 0, 'options' => [['name' => 'A', 'priceDelta' => 'abc']]]), 'Selisih harga harus angka.');
    }

    // --- foto menu -----------------------------------------------------------------

    public function test_tipe_dideteksi_dari_isi_file(): void
    {
        $this->assertSame('image/png', MenuImages::detect("\x89PNG\r\n\x1A\n\0\0\0\0\0")['contentType']);
        $this->assertSame('image/jpeg', MenuImages::detect("\xFF\xD8\xFF\xE0\0\0")['contentType']);
        $this->assertSame('image/webp', MenuImages::detect("RIFF\0\0\0\0WEBPVP8 ")['contentType']);
        $this->assertNull(MenuImages::detect('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        $this->assertNull(MenuImages::detect('GIF89a......'));
        $this->assertNull(MenuImages::detect('<?php echo 1;'));
    }

    public function test_file_berbahaya_yang_menyamar_sebagai_png_ditolak(): void
    {
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<?php system($_GET["c"]); ?>'] as $content) {
            $fake = UploadedFile::fake()->createWithContent('foto.png', $content);
            $this->expectInvalid(fn () => MenuImages::read($fake), 'Format foto harus JPG, PNG, atau WebP.');
        }
    }

    public function test_file_lebih_dari_2_mb_ditolak_tanpa_file_null(): void
    {
        $big = UploadedFile::fake()->createWithContent('big.jpg', "\xFF\xD8\xFF".str_repeat("\0", 2 * 1024 * 1024));
        $this->expectInvalid(fn () => MenuImages::read($big), 'Ukuran foto maksimal 2 MB.');
        $this->assertNull(MenuImages::read(null));
        $this->assertNull(MenuImages::read(UploadedFile::fake()->createWithContent('kosong.png', '')));
    }

    public function test_nama_file_dibuat_server(): void
    {
        $path = MenuImages::path(self::id(1), self::id(2), 'png');
        $this->assertMatchesRegularExpression('#^'.self::id(1).'/'.self::id(2).'/[0-9a-f-]{36}\.png$#', $path);
    }

    /** @return iterable<array{string, string}> */
    public static function slugs(): iterable
    {
        yield ['Kopi Susu Gula Aren', 'kopi-susu-gula-aren'];
        yield ['  Matcha   Latte!! ', 'matcha-latte'];
        yield ['Café Crème', 'cafe-creme'];
        yield ['!!!', ''];
    }

    #[DataProvider('slugs')]
    public function test_slugify(string $input, string $expected): void
    {
        $this->assertSame($expected, Slug::make($input));
    }
}
