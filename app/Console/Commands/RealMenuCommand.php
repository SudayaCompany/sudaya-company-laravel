<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditContext;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menu asli Sudaya dari file owner "menu coffee fix sudaya baru.docx" (11 November 2026).
 * File hanya berisi nama menu tanpa harga, jadi semua menu masuk NONAKTIF dengan harga Rp0:
 * admin mengisi harga di Admin → Menu lalu mengaktifkannya. Tidak ada harga yang dikarang.
 * Idempoten (SKU SDY-xx); `--remove` menghapus menu yang dibuat perintah ini.
 */
class RealMenuCommand extends Command
{
    protected $signature = 'sudaya:menu-asli {--company=sudaya : Kode company} {--remove : Hapus menu asli hasil impor ini}';

    protected $description = 'Memasukkan 22 menu asli Sudaya (nonaktif, harga diisi admin)';

    /** [kategori, nama, penyajian]: 'ice_hot' = pilihan Ice/Hot, 'ice' = hanya ice, null = tanpa pilihan. */
    public const MENU = [
        ['Coffee Espresso', 'Americano', 'ice_hot'],
        ['Coffee Espresso', 'Cappuccino', 'ice_hot'],
        ['Coffee Espresso', 'Caffe Latte', 'ice_hot'],
        ['Coffee Espresso', 'Seasalt Caramel Latte', 'ice'],
        ['Coffee Espresso', 'Butterscotch Latte', 'ice_hot'],
        ['Coffee Espresso', 'Hazelnut Latte', 'ice_hot'],
        ['Coffee Espresso', 'Vanilla Latte', 'ice_hot'],
        ['Coffee Espresso', 'Sudaya Coffee', 'ice'],
        ['Non Coffee', 'Chocolate', 'ice_hot'],
        ['Non Coffee', 'Chocolate Berry', 'ice'],
        ['Non Coffee', 'Maren', 'ice'],
        ['Non Coffee', 'Matcha Bary', 'ice'],
        ['Non Coffee', 'Matcha Latte', 'ice_hot'],
        ['Non Coffee', 'Strawberry Manis', 'ice'],
        ['Tea', 'Sweet Tea', null],
        ['Tea', 'Lemon Tea', null],
        ['Tea', 'Lychee Tea', null],
        ['Tea', 'Thai Tea', null],
        ['Mocktail', 'Lemonade Telang', null],
        ['Mocktail', 'Twilight', null],
        ['Manual Brew', 'V60', null],
        ['Manual Brew', 'Japanese', null],
    ];

    public function handle(CatalogAdmin $catalog, AccessLoader $access): int
    {
        $company = DB::table('companies')->where('code', $this->option('company'))->first(['id']);
        $brand = $company === null ? null : DB::table('brands')->where('company_id', $company->id)->orderBy('created_at')->first(['id']);
        $adminId = $company === null ? null : DB::table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.company_id', $company->id)->where('roles.code', 'super_admin')->where('users.is_active', true)
            ->value('users.id');
        $actor = $adminId === null ? null : $access->currentUser($adminId);
        if ($brand === null || $actor === null) {
            $this->error('Company/brand atau Super Admin aktif tidak ditemukan.');

            return self::FAILURE;
        }
        $audit = new AuditContext('127.0.0.1', 'sudaya:menu-asli');
        $existing = fn () => DB::table('products')->where('brand_id', $brand->id)->where('sku', 'like', 'SDY-%')->whereNull('deleted_at');

        if ($this->option('remove')) {
            $ids = $existing()->pluck('id');
            foreach ($ids as $id) {
                $catalog->deleteProduct($actor, $id, $audit);
            }
            $this->info("Menu asli dihapus: {$ids->count()} produk.");

            return self::SUCCESS;
        }
        if ($existing()->exists()) {
            $this->info('Menu asli sudah dimasukkan.');

            return self::SUCCESS;
        }

        // Satu transaksi: gagal di tengah → tidak ada menu setengah jadi.
        $categories = DB::transaction(function () use ($brand, $catalog, $actor, $audit) {
            $categories = [];
            $category = function (string $name) use (&$categories, $brand, $catalog, $actor, $audit) {
                return $categories[$name] ??= DB::table('categories')->where('brand_id', $brand->id)->where('name', $name)->value('id')
                    ?? $catalog->createCategory($actor, $brand->id, ['name' => $name], $audit)['id'];
            };
            $serving = DB::table('modifier_groups')->where('brand_id', $brand->id)->where('name', 'Penyajian')->value('id')
                ?? $catalog->createModifierGroup($actor, $brand->id, [
                    'name' => 'Penyajian', 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
                    'options' => [['name' => 'Ice', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Hot', 'priceDelta' => 0]],
                ], $audit)['id'];

            foreach (self::MENU as $i => [$categoryName, $name, $serve]) {
                // Slug bentrok dengan menu contoh (mis. Americano) → akhiran -sudaya.
                $slug = Str::slug($name);
                if (DB::table('products')->where('brand_id', $brand->id)->where('slug', $slug)->exists()) {
                    $slug .= '-sudaya';
                }
                $catalog->createProduct($actor, $brand->id, [
                    'name' => $name,
                    'slug' => $slug,
                    'sku' => sprintf('SDY-%02d', $i + 1),
                    'categoryId' => $category($categoryName),
                    'basePrice' => '0',
                    'description' => $serve === 'ice' ? 'Disajikan dingin (ice).' : null,
                    'isActive' => false,
                    'modifierGroupIds' => $serve === 'ice_hot' ? [$serving] : [],
                ], null, $audit);
            }

            return $categories;
        });
        $this->info('Menu asli dimasukkan: '.count(self::MENU).' menu NONAKTIF dalam '.count($categories).' kategori. Isi harga di Admin → Menu lalu aktifkan.');

        return self::SUCCESS;
    }
}
