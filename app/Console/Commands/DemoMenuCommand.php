<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditContext;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menu CONTOH untuk presentasi/latihan (permintaan owner 10 November 2026): kategori, varian
 * (ukuran, gula, es), dan menu dengan harga contoh. Dibuat lewat service katalog yang sama
 * dengan admin (tervalidasi, tercatat di log aktivitas). Semua SKU berawalan CONTOH- dan
 * deskripsinya menyebut "contoh", sehingga mudah dikenali dan dihapus (--remove).
 * Bukan untuk production sebelum diganti menu asli.
 */
class DemoMenuCommand extends Command
{
    protected $signature = 'sudaya:demo-menu {--company=sudaya : Kode company} {--remove : Nonaktifkan & hapus menu contoh}';

    protected $description = 'Memasang (atau menghapus) menu contoh untuk presentasi';

    private const NOTE = 'Menu contoh untuk presentasi — ubah atau hapus di Admin → Menu.';

    public function handle(CatalogAdmin $catalog, AccessLoader $access): int
    {
        if (app()->isProduction() && ! $this->option('remove')) {
            $this->error('Menu contoh tidak dipasang di production.');

            return self::FAILURE;
        }
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
        $audit = new AuditContext('127.0.0.1', 'sudaya:demo-menu');

        if ($this->option('remove')) {
            $ids = DB::table('products')->where('brand_id', $brand->id)->where('sku', 'like', 'CONTOH-%')->whereNull('deleted_at')->pluck('id');
            foreach ($ids as $id) {
                $catalog->deleteProduct($actor, $id, $audit);
            }
            $this->info("Menu contoh dihapus: {$ids->count()} produk (kategori & varian contoh bisa dihapus manual bila tidak dipakai).");

            return self::SUCCESS;
        }
        if (DB::table('products')->where('brand_id', $brand->id)->where('sku', 'like', 'CONTOH-%')->whereNull('deleted_at')->exists()) {
            $this->info('Menu contoh sudah terpasang.');

            return self::SUCCESS;
        }

        $category = fn (string $name) => DB::table('categories')->where('brand_id', $brand->id)->where('name', $name)->value('id')
            ?? $catalog->createCategory($actor, $brand->id, ['name' => $name], $audit)['id'];
        $group = fn (string $name, array $raw) => DB::table('modifier_groups')->where('brand_id', $brand->id)->where('name', $name)->value('id')
            ?? $catalog->createModifierGroup($actor, $brand->id, ['name' => $name, ...$raw], $audit)['id'];

        $coffee = $category('Kopi');
        $nonCoffee = $category('Non-Kopi');
        $food = $category('Makanan & Snack');
        $size = $group('Ukuran', ['selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Regular', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Large', 'priceDelta' => 5000]]]);
        $sugar = $group('Tingkat gula', ['selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Normal', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Less sugar', 'priceDelta' => 0], ['name' => 'Tanpa gula', 'priceDelta' => 0]]]);
        $ice = $group('Es', ['selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
            'options' => [['name' => 'Normal', 'priceDelta' => 0, 'isDefault' => true], ['name' => 'Less ice', 'priceDelta' => 0], ['name' => 'Tanpa es', 'priceDelta' => 0]]]);
        $extra = $group('Tambahan', ['selection' => 'multiple', 'isRequired' => false, 'minSelect' => 0, 'maxSelect' => 3,
            'options' => [['name' => 'Extra shot', 'priceDelta' => 5000], ['name' => 'Oat milk', 'priceDelta' => 7000], ['name' => 'Sirup vanilla', 'priceDelta' => 4000]]]);

        $menu = [
            ['Es Kopi Susu Gula Aren', $coffee, 22000, [$size, $sugar, $ice, $extra], 'Espresso, susu segar, dan gula aren.'],
            ['Americano', $coffee, 18000, [$size, $sugar, $ice, $extra], 'Espresso dengan air, panas atau dingin.'],
            ['Cafe Latte', $coffee, 25000, [$size, $sugar, $ice, $extra], 'Espresso dengan susu steamed yang lembut.'],
            ['Cappuccino', $coffee, 25000, [$size, $sugar, $extra], 'Espresso, susu, dan foam tebal.'],
            ['Matcha Latte', $nonCoffee, 26000, [$size, $sugar, $ice], 'Matcha dengan susu segar.'],
            ['Coklat', $nonCoffee, 23000, [$size, $sugar, $ice], 'Coklat pekat dengan susu.'],
            ['Lemon Tea', $nonCoffee, 15000, [$sugar, $ice], 'Teh segar dengan perasan lemon.'],
            ['Croissant Butter', $food, 20000, [], 'Croissant renyah dengan butter.'],
            ['Roti Bakar Coklat Keju', $food, 18000, [], 'Roti bakar isi coklat dan keju.'],
        ];
        foreach ($menu as $i => [$name, $categoryId, $price, $groups, $desc]) {
            $catalog->createProduct($actor, $brand->id, [
                'name' => $name, 'sku' => sprintf('CONTOH-%02d', $i + 1), 'categoryId' => $categoryId, 'basePrice' => (string) $price,
                'description' => "{$desc} ".self::NOTE, 'isActive' => true, 'modifierGroupIds' => $groups,
            ], null, $audit);
        }
        $this->info('Menu contoh dipasang: '.count($menu).' menu, 3 kategori, 4 grup varian (foto memakai placeholder).');

        return self::SUCCESS;
    }
}
