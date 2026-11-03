<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Services\Catalog\SupabaseMenuImageStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Padanan tests/integration/catalog.test.ts, ditambah daftar uji M2 dari owner. */
class CatalogTest extends LiveTestCase
{
    public const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Storage palsu: mencatat upload dan hapus tanpa menyentuh Supabase. */
    private object $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new class implements MenuImageStorage
        {
            public array $uploaded = [];

            public array $removed = [];

            public function upload(string $path, string $bytes, string $contentType): void
            {
                $this->uploaded[] = $path;
            }

            public function remove(array $paths): void
            {
                array_push($this->removed, ...$paths);
            }
        };
        $this->app->instance(MenuImageStorage::class, $this->storage);
    }

    protected function fixtures(LiveContext $ctx): array
    {
        $a = $ctx->createCompany('cata');
        $b = $ctx->createCompany('catb');

        return [
            'A' => $a,
            'B' => $b,
            'adminA' => $ctx->createUser($a, [['role' => 'super_admin']]),
            'managerA' => $ctx->createUser($a, [['role' => 'manager', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($a, [['role' => 'barista', 'outlet' => 'outlet']]),
            'adminB' => $ctx->createUser($b, [['role' => 'super_admin']]),
        ];
    }

    private function catalog(): CatalogAdmin
    {
        return app(CatalogAdmin::class);
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function png(): array
    {
        return ['bytes' => base64_decode(self::TINY_PNG), 'ext' => 'png', 'contentType' => 'image/png'];
    }

    private function expectAccess(string $reason, callable $call, ?string $message = null): void
    {
        try {
            $call();
            $this->fail("diharapkan AccessException {$reason}");
        } catch (AccessException $error) {
            $this->assertSame($reason, $error->reason, $error->getMessage());
            if ($message !== null) {
                $this->assertSame($message, $error->getMessage());
            }
        }
    }

    private static int $menus = 0;

    /**
     * Company baru berisi kategori, dua grup modifier, dan dua produk. Tiap test
     * mendapat company sendiri agar slug dan isi menu tidak saling bertabrakan.
     */
    private function seedMenu(): array
    {
        $A = $this->ctx()->createCompany('menu'.++self::$menus);
        $adminUser = $this->ctx()->createUser($A, [['role' => 'super_admin']]);
        $admin = $this->ctx()->load($adminUser['id']);
        $coffee = $this->catalog()->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $this->audit())['id'];
        $size = $this->catalog()->createModifierGroup($admin, $A['brandId'], [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Regular', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Large', 'priceDelta' => 6000]],
        ], $this->audit())['id'];
        $topping = $this->catalog()->createModifierGroup($admin, $A['brandId'], [
            'name' => 'Topping', 'selection' => 'multiple', 'isRequired' => false, 'minSelect' => 0, 'maxSelect' => 2,
            'options' => [['name' => 'Extra Shot', 'priceDelta' => 5000], ['name' => 'Cream', 'priceDelta' => 0]],
        ], $this->audit())['id'];
        $latte = $this->catalog()->createProduct($admin, $A['brandId'], [
            'name' => 'Iced Latte Aren', 'sku' => 'cf-latte', 'categoryId' => $coffee, 'basePrice' => '27000',
            'isActive' => true, 'modifierGroupIds' => [$size, $topping],
        ], $this->png(), $this->audit())['id'];
        $americano = $this->catalog()->createProduct($admin, $A['brandId'], [
            'name' => 'Americano', 'categoryId' => $coffee, 'basePrice' => '20000', 'isActive' => true, 'modifierGroupIds' => [$size, $topping],
        ], null, $this->audit())['id'];

        return [...compact('coffee', 'size', 'topping', 'latte', 'americano'), 'company' => $A, 'admin' => $adminUser['id']];
    }

    // --- kategori ------------------------------------------------------------------

    public function test_admin_membuat_dan_mengubah_kategori_tercatat_di_audit(): void
    {
        $A = static::$fixture['A'];
        $created = $this->catalog()->createCategory($this->actor('adminA'), $A['brandId'], ['name' => 'Non Coffee', 'sortOrder' => 2], $this->audit());
        $this->assertSame('non-coffee', DB::table('categories')->where('id', $created['id'])->value('slug'));

        $this->catalog()->updateCategory($this->actor('adminA'), $created['id'], ['name' => 'Non-Coffee', 'slug' => 'non-coffee', 'sortOrder' => 3, 'isActive' => false], $this->audit());
        $row = DB::table('categories')->where('id', $created['id'])->first();
        $this->assertSame('Non-Coffee', $row->name);
        $this->assertFalse((bool) $row->is_active);

        $log = DB::table('audit_logs')->where('entity_id', $created['id'])->where('action', 'category.updated')->first();
        $this->assertEquals(['name' => 'Non-Coffee', 'sortOrder' => 3, 'isActive' => false], json_decode($log->after, true));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'category.created', $created['id']));
    }

    public function test_kategori_berisi_produk_tidak_bisa_dihapus_dan_slug_duplikat_ditolak(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $this->expectAccess('conflict', fn () => $this->catalog()->deleteCategory($this->ctx()->load($menu['admin']), $menu['coffee'], $this->audit()));

        $tea = $this->catalog()->createCategory($this->ctx()->load($menu['admin']), $A['brandId'], ['name' => 'Tea'], $this->audit())['id'];
        $this->expectAccess('conflict', fn () => $this->catalog()->updateCategory($this->ctx()->load($menu['admin']), $tea, ['name' => 'Tea', 'slug' => 'coffee'], $this->audit()), 'Slug kategori sudah dipakai kategori lain.');
        $this->catalog()->deleteCategory($this->ctx()->load($menu['admin']), $tea, $this->audit());
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'category.deleted', $tea));
        // Soft delete: slug boleh dipakai lagi.
        $this->catalog()->createCategory($this->ctx()->load($menu['admin']), $A['brandId'], ['name' => 'Tea'], $this->audit());
    }

    // --- produk --------------------------------------------------------------------

    public function test_admin_membuat_produk_dengan_foto_dan_modifier_tercatat_di_audit(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $row = DB::table('products')->where('id', $menu['latte'])->first();

        $this->assertSame('iced-latte-aren', $row->slug);
        $this->assertSame('CF-LATTE', $row->sku);
        $this->assertSame(27000, (int) $row->base_price);
        $this->assertSame($row->image_path, $this->storage->uploaded[0]);
        $this->assertMatchesRegularExpression("#^{$A['id']}/{$A['brandId']}/[0-9a-f-]{36}\\.png$#", $row->image_path);
        $this->assertSame([$menu['size'], $menu['topping']], DB::table('product_modifier_groups')->where('product_id', $menu['latte'])->orderBy('sort_order')->pluck('group_id')->all());
        $after = json_decode(DB::table('audit_logs')->where('entity_id', $menu['latte'])->where('action', 'product.created')->value('after'), true);
        $this->assertTrue($after['hasImage']);
        $this->assertSame(27000, $after['basePrice']);
    }

    public function test_perubahan_harga_dicatat_sebagai_price_changed(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $edit = $this->catalog()->productForEdit($this->ctx()->load($menu['admin']), $menu['americano']);
        $this->catalog()->updateProduct($this->ctx()->load($menu['admin']), $menu['americano'], [...$edit, 'basePrice' => '22000'], ['image' => null, 'remove' => false], $this->audit());

        $log = DB::table('audit_logs')->where('entity_id', $menu['americano'])->where('action', 'product.price_changed')->first();
        $this->assertSame(['basePrice' => 20000], json_decode($log->before, true));
        $this->assertSame(['basePrice' => 22000], json_decode($log->after, true));
        // Harga tidak ikut product.updated: hanya event harga.
        $this->assertSame(0, $this->ctx()->countAudit($A['id'], 'product.updated', $menu['americano']));
    }

    public function test_ganti_foto_foto_baru_diunggah_foto_lama_dihapus_setelah_tersimpan(): void
    {
        $menu = $this->seedMenu();
        $old = DB::table('products')->where('id', $menu['latte'])->value('image_path');
        $edit = $this->catalog()->productForEdit($this->ctx()->load($menu['admin']), $menu['latte']);
        $this->catalog()->updateProduct($this->ctx()->load($menu['admin']), $menu['latte'], $edit, ['image' => $this->png(), 'remove' => false], $this->audit());

        $new = DB::table('products')->where('id', $menu['latte'])->value('image_path');
        $this->assertNotSame($old, $new);
        $this->assertSame([$old], $this->storage->removed);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_id', $menu['latte'])->where('action', 'product.image_changed')->count());

        $this->catalog()->updateProduct($this->ctx()->load($menu['admin']), $menu['latte'], $edit, ['image' => null, 'remove' => true], $this->audit());
        $this->assertNull(DB::table('products')->where('id', $menu['latte'])->value('image_path'));
        $this->assertSame([$old, $new], $this->storage->removed);
    }

    public function test_sku_dan_slug_duplikat_ditolak_foto_yang_terunggah_dihapus_lagi(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $base = ['categoryId' => $menu['coffee'], 'basePrice' => '1000', 'isActive' => true, 'modifierGroupIds' => []];

        $this->expectAccess('conflict', fn () => $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [...$base, 'name' => 'Latte Lain', 'sku' => 'CF-LATTE'], $this->png(), $this->audit()), 'SKU sudah dipakai produk lain.');
        $this->assertSame(end($this->storage->uploaded), end($this->storage->removed));
        $this->expectAccess('conflict', fn () => $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [...$base, 'name' => 'Americano'], null, $this->audit()), 'Slug produk sudah dipakai produk lain.');

        // Brand lain boleh memakai slug dan SKU yang sama.
        $B = static::$fixture['B'];
        $catB = $this->catalog()->createCategory($this->actor('adminB'), $B['brandId'], ['name' => 'Coffee'], $this->audit())['id'];
        $this->catalog()->createProduct($this->actor('adminB'), $B['brandId'], [...$base, 'categoryId' => $catB, 'name' => 'Americano', 'sku' => 'CF-LATTE'], null, $this->audit());
    }

    public function test_harga_negatif_dan_input_tidak_valid_ditolak_tanpa_menyimpan(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $before = DB::table('products')->where('company_id', $A['id'])->count();
        $this->expectAccess('invalid', fn () => $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [
            'name' => 'Rugi', 'categoryId' => $menu['coffee'], 'basePrice' => '-500', 'isActive' => true, 'modifierGroupIds' => [],
        ], null, $this->audit()), 'Harga minimal 0.');
        $this->assertSame($before, DB::table('products')->where('company_id', $A['id'])->count());
        $this->assertSame([], array_diff($this->storage->uploaded, DB::table('products')->pluck('image_path')->all()));
    }

    public function test_mass_assignment_company_brand_image_path_dari_input_diabaikan(): void
    {
        $B = static::$fixture['B'];
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $id = $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [
            'name' => 'Cold Brew', 'categoryId' => $menu['coffee'], 'basePrice' => '25000', 'isActive' => true, 'modifierGroupIds' => [],
            'companyId' => $B['id'], 'brandId' => $B['brandId'], 'imagePath' => 'evil/path.png', 'company_id' => $B['id'], 'image_path' => 'x',
        ], null, $this->audit())['id'];
        $row = DB::table('products')->where('id', $id)->first();
        $this->assertSame($A['id'], $row->company_id);
        $this->assertSame($A['brandId'], $row->brand_id);
        $this->assertNull($row->image_path);
    }

    public function test_modifier_dan_kategori_brand_lain_ditolak(): void
    {
        $B = static::$fixture['B'];
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $groupB = $this->catalog()->createModifierGroup($this->actor('adminB'), $B['brandId'], [
            'name' => 'Size B', 'selection' => 'single', 'isRequired' => false, 'minSelect' => 0, 'maxSelect' => 1, 'options' => [['name' => 'R', 'priceDelta' => 0]],
        ], $this->audit())['id'];
        $base = ['name' => 'Silang', 'basePrice' => '1000', 'isActive' => true];

        $this->expectAccess('invalid', fn () => $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [...$base, 'categoryId' => $menu['coffee'], 'modifierGroupIds' => [$groupB]], null, $this->audit()), 'Grup modifier tidak valid.');
        $catB = DB::table('categories')->where('company_id', $B['id'])->value('id') ?? $this->catalog()->createCategory($this->actor('adminB'), $B['brandId'], ['name' => 'X'], $this->audit())['id'];
        $this->expectAccess('invalid', fn () => $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [...$base, 'categoryId' => $catB, 'modifierGroupIds' => []], null, $this->audit()), 'Kategori tidak valid.');
    }

    public function test_pencarian_filter_sorting_dan_pagination(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        foreach (range(1, 21) as $n) {
            $this->catalog()->createProduct($this->ctx()->load($menu['admin']), $A['brandId'], [
                'name' => sprintf('Snack %02d', $n), 'categoryId' => $menu['coffee'], 'basePrice' => (string) ($n * 1000), 'isActive' => $n !== 5, 'modifierGroupIds' => [],
            ], null, $this->audit());
        }
        $list = fn (array $params) => $this->catalog()->listProducts($this->ctx()->load($menu['admin']), $A['brandId'], $A['outletId'], CatalogInput::productListParams($params));

        $page1 = $list(['q' => 'snack']);
        $this->assertSame(21, $page1['total']);
        $this->assertSame(2, $page1['pageCount']);
        $this->assertCount(20, $page1['rows']);
        $this->assertCount(1, $list(['q' => 'snack', 'page' => '2'])['rows']);
        $this->assertSame(2, $list(['q' => 'snack', 'page' => '99'])['page']);
        $this->assertSame('Snack 21', $list(['q' => 'snack', 'sort' => 'price', 'dir' => 'desc'])['rows'][0]['name']);
        $this->assertSame(1, $list(['q' => 'snack', 'status' => 'inactive'])['total']);
        $this->assertSame(1, $list(['q' => 'cf-lat'])['total']);
        // Karakter LIKE di kata kunci tidak menjadi wildcard.
        $this->assertSame(0, $list(['q' => '%'])['total']);
        // Parameter rusak jatuh ke default, tidak error.
        $this->assertSame(23, $list(['sort' => 'drop table', 'dir' => 'x', 'page' => '-3', 'category' => 'bukan-uuid'])['total']);
    }

    // --- modifier ------------------------------------------------------------------

    public function test_modifier_valid_tersimpan_opsi_grup_lain_ditolak_harga_opsi_tercatat(): void
    {
        $menu = $this->seedMenu();
        $groups = collect($this->catalog()->listModifierGroups($this->ctx()->load($menu['admin']), $menu['company']['brandId']))->keyBy('id');
        $size = $groups[$menu['size']];
        $topping = $groups[$menu['topping']];
        $this->assertSame(2, $size['productCount']);
        $this->assertSame(['Regular', 'Large'], array_column($size['options'], 'name'));

        $this->expectAccess('not_found', fn () => $this->catalog()->updateModifierGroup($this->ctx()->load($menu['admin']), $menu['size'], [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['id' => $topping['options'][0]['id'], 'name' => 'Curian', 'priceDelta' => 0]],
        ], $this->audit()));

        $this->catalog()->updateModifierGroup($this->ctx()->load($menu['admin']), $menu['size'], [
            'name' => 'Size', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => array_map(fn ($o) => [...$o, 'priceDelta' => $o['name'] === 'Large' ? 7000 : $o['priceDelta']], $size['options']),
        ], $this->audit());
        $after = DB::table('audit_logs')->where('action', 'modifier_group.updated')->where('entity_id', $menu['size'])->value('after');
        $this->assertStringContainsString('Large (+7000)', $after);
        // Id opsi lama dipertahankan, tidak dibuat ulang.
        $this->assertSame(array_column($size['options'], 'id'), DB::table('modifiers')->where('group_id', $menu['size'])->orderBy('sort_order')->pluck('id')->all());
    }

    public function test_modifier_tidak_valid_ditolak(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('invalid', fn () => $this->catalog()->createModifierGroup($this->actor('adminA'), $A['brandId'], [
            'name' => 'Sugar', 'selection' => 'multiple', 'isRequired' => false, 'minSelect' => 3, 'maxSelect' => 2, 'options' => [['name' => 'A', 'priceDelta' => 0]],
        ], $this->audit()), 'Minimal pilihan tidak boleh melebihi maksimal.');
        $this->expectAccess('invalid', fn () => $this->catalog()->createModifierGroup($this->actor('adminA'), $A['brandId'], [
            'name' => 'Sugar', 'selection' => 'single', 'isRequired' => false, 'minSelect' => 0, 'maxSelect' => 1, 'options' => [['name' => 'A', 'priceDelta' => '1.5']],
        ], $this->audit()), 'Selisih harga harus bilangan bulat.');
        $this->assertSame(0, DB::table('modifier_groups')->where('company_id', $A['id'])->where('name', 'Sugar')->count());
    }

    public function test_hapus_grup_modifier_melepas_relasi_produk(): void
    {
        $menu = $this->seedMenu();
        $this->catalog()->deleteModifierGroup($this->ctx()->load($menu['admin']), $menu['topping'], $this->audit());
        $this->assertSame(0, DB::table('product_modifier_groups')->where('group_id', $menu['topping'])->count());
        $this->assertSame(0, DB::table('modifiers')->where('group_id', $menu['topping'])->count());
        $this->assertTrue(DB::table('products')->where('id', $menu['latte'])->whereNull('deleted_at')->exists());
    }

    // --- permission & scope --------------------------------------------------------

    public function test_barista_tidak_bisa_melihat_maupun_membuat_produk(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('forbidden', fn () => $this->catalog()->listProducts($this->actor('baristaA'), $A['brandId'], null, CatalogInput::productListParams([])));
        $this->expectAccess('forbidden', fn () => $this->catalog()->createCategory($this->actor('baristaA'), $A['brandId'], ['name' => 'Ilegal'], $this->audit()));
    }

    public function test_manager_outlet_boleh_lihat_dan_sold_out_di_outletnya_tidak_boleh_ubah_master(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $manager = $this->ctx()->load($this->ctx()->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']])['id']);
        $this->assertSame(2, $this->catalog()->listProducts($manager, $A['brandId'], $A['outletId'], CatalogInput::productListParams([]))['total']);

        $this->catalog()->setProductAvailability($manager, ['productId' => $menu['latte'], 'outletId' => $A['outletId'], 'isAvailable' => false], $this->audit());
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'product.availability_changed', $menu['latte']));
        $this->assertSame($A['outletId'], DB::table('audit_logs')->where('action', 'product.availability_changed')->where('entity_id', $menu['latte'])->value('outlet_id'));

        $edit = $this->catalog()->productForEdit($this->ctx()->load($menu['admin']), $menu['latte']);
        $this->expectAccess('forbidden', fn () => $this->catalog()->updateProduct($manager, $menu['latte'], [...$edit, 'basePrice' => '1'], ['image' => null, 'remove' => false], $this->audit()));
        $this->expectAccess('forbidden', fn () => $this->catalog()->createCategory($manager, $A['brandId'], ['name' => 'Ilegal'], $this->audit()));
    }

    public function test_company_b_tidak_bisa_membaca_atau_mengubah_katalog_company_a(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $adminB = $this->actor('adminB');

        $this->expectAccess('not_found', fn () => $this->catalog()->createCategory($adminB, $A['brandId'], ['name' => 'Susup'], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->catalog()->productForEdit($adminB, $menu['latte']));
        $this->expectAccess('not_found', fn () => $this->catalog()->deleteProduct($adminB, $menu['latte'], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->catalog()->setProductAvailability($adminB, ['productId' => $menu['latte'], 'outletId' => $A['outletId'], 'isAvailable' => false], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->catalog()->updateModifierGroup($adminB, $menu['size'], ['name' => 'X', 'selection' => 'single', 'isRequired' => false, 'minSelect' => 0, 'maxSelect' => 1, 'options' => [['name' => 'A', 'priceDelta' => 0]]], $this->audit()));
        $this->assertSame([], $this->catalog()->listCategories($adminB, $A['brandId']));
        $this->assertSame(0, $this->catalog()->listProducts($adminB, $A['brandId'], null, CatalogInput::productListParams([]))['total']);
    }

    // --- storefront customer -------------------------------------------------------

    public function test_storefront_hanya_produk_aktif_sold_out_ditandai_harga_dari_db(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $admin = $this->ctx()->load($menu['admin']);
        $this->catalog()->setProductAvailability($admin, ['productId' => $menu['americano'], 'outletId' => $A['outletId'], 'isAvailable' => false], $this->audit());

        $storefront = app(Storefront::class)->load($A['outletCode']);
        $products = array_column($storefront['products'], null, 'id');
        $this->assertFalse($products[$menu['americano']]['isAvailable']);
        $latte = $products[$menu['latte']];
        $this->assertSame(27000, $latte['price']);
        $this->assertSame(['Size', 'Topping'], array_column($latte['groups'], 'name'));
        $this->assertSame(MenuImages::url(DB::table('products')->where('id', $menu['latte'])->value('image_path')), $latte['imageUrl']);
        // Data publik tidak membocorkan kolom internal.
        foreach (['companyId', 'company_id', 'sku', 'imagePath', 'image_path', 'brandId'] as $key) {
            $this->assertArrayNotHasKey($key, $latte);
        }
        $this->assertCount(1, $latte['defaultModifierIds']);

        $large = collect($latte['groups'][0]['modifiers'])->firstWhere('name', 'Large');
        $shot = collect($latte['groups'][1]['modifiers'])->firstWhere('name', 'Extra Shot');
        $quote = Storefront::quote($storefront, [
            ['productId' => $menu['latte'], 'modifierIds' => [$large['id'], $shot['id']], 'quantity' => 2],
            ['productId' => $menu['latte'], 'modifierIds' => [], 'quantity' => 1],
            ['productId' => $menu['americano'], 'modifierIds' => [$latte['groups'][0]['modifiers'][0]['id']], 'quantity' => 1],
        ]);
        $this->assertSame((27000 + 6000 + 5000) * 2, $quote['total']);
        $this->assertSame('Pilih Size.', $quote['lines'][1]['error']);
        $this->assertSame('Maaf, produk ini baru saja tidak tersedia.', $quote['lines'][2]['error']);
    }

    public function test_produk_nonaktif_dan_terhapus_tidak_tampil_soft_delete_menjaga_histori(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $admin = $this->ctx()->load($menu['admin']);
        $this->catalog()->setProductActive($admin, ['productId' => $menu['americano'], 'isActive' => false], $this->audit());
        $this->catalog()->deleteProduct($admin, $menu['latte'], $this->audit());

        $this->assertSame([], app(Storefront::class)->load($A['outletCode'])['products']);
        // Baris tetap ada (order_items merujuk produk tanpa cascade), hanya ditandai terhapus.
        $deleted = DB::table('products')->where('id', $menu['latte'])->first();
        $this->assertNotNull($deleted->deleted_at);
        $this->assertFalse((bool) $deleted->is_active);
        $this->assertSame(1, DB::table('product_modifier_groups')->where('product_id', $menu['latte'])->where('group_id', $menu['size'])->count());
        $this->assertEquals(['name' => 'Iced Latte Aren', 'sku' => 'CF-LATTE', 'basePrice' => 27000], json_decode(DB::table('audit_logs')->where('action', 'product.deleted')->where('entity_id', $menu['latte'])->value('before'), true));
        // Produk terhapus tidak bisa diedit lagi, dan SKU-nya boleh dipakai produk baru.
        $this->expectAccess('not_found', fn () => $this->catalog()->productForEdit($admin, $menu['latte']));
        $this->catalog()->createProduct($admin, $A['brandId'], ['name' => 'Iced Latte Aren', 'sku' => 'CF-LATTE', 'categoryId' => $menu['coffee'], 'basePrice' => '28000', 'isActive' => true, 'modifierGroupIds' => []], null, $this->audit());

        $this->assertNull(app(Storefront::class)->load('TIDAKADA'));
        $this->assertNull(app(Storefront::class)->load("x' or 1=1 --"));
    }

    public function test_cache_menu_dibuang_saat_admin_mengubah_menu(): void
    {
        $menu = $this->seedMenu();
        $A = $menu['company'];
        $before = collect(app(Storefront::class)->cached($A['outletCode'])['products'])->firstWhere('id', $menu['americano']);
        $this->assertSame(20000, $before['price']);

        $edit = $this->catalog()->productForEdit($this->ctx()->load($menu['admin']), $menu['americano']);
        $this->catalog()->updateProduct($this->ctx()->load($menu['admin']), $menu['americano'], [...$edit, 'basePrice' => '21000'], ['image' => null, 'remove' => false], $this->audit());

        $after = collect(app(Storefront::class)->cached($A['outletCode'])['products'])->firstWhere('id', $menu['americano']);
        $this->assertSame(21000, $after['price']);
    }

    // --- Supabase Storage sungguhan -----------------------------------------------

    public function test_upload_lewat_server_publik_bisa_membaca_anon_tidak_bisa_menulis(): void
    {
        $A = static::$fixture['A'];
        $storage = new SupabaseMenuImageStorage;
        $path = "{$A['id']}/{$A['brandId']}/storage-test.png";
        $storage->upload($path, base64_decode(self::TINY_PNG), 'image/png');

        $public = Http::get(MenuImages::url($path));
        $this->assertSame(200, $public->status());
        $this->assertStringContainsString('image/png', $public->header('Content-Type'));

        $anon = Http::baseUrl(config('services.supabase.url'))->withHeaders(['apikey' => config('services.supabase.publishable_key')])->withToken(config('services.supabase.publishable_key'));
        $this->assertTrue($anon->withBody(base64_decode(self::TINY_PNG), 'image/png')->post("/storage/v1/object/menu-images/{$A['id']}/{$A['brandId']}/anon.png")->failed());
        $anon->delete('/storage/v1/object/menu-images', ['prefixes' => [$path]]);
        $this->assertSame(200, Http::get(MenuImages::url($path).'?masih-ada')->status());

        $storage->remove([$path]);
        $this->assertNotSame(200, Http::get(MenuImages::url($path).'?setelah-hapus')->status());
    }

    public function test_bucket_selain_menu_images_tidak_ada_yang_publik(): void
    {
        $buckets = $this->ctx()->admin()->get('/storage/v1/bucket')->json();
        $this->assertSame(['menu-images'], array_values(array_map(fn ($b) => $b['id'], array_filter($buckets, fn ($b) => $b['public']))));
    }
}
