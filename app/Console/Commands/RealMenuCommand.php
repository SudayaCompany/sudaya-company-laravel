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
 *
 * File owner hanya berisi nama menu, tanpa harga. Keputusan owner 12 November: 22 menu
 * langsung AKTIF dan tampil "Harga menyusul" (belum bisa dipesan) sampai harganya diisi.
 * Harga bisa masuk dua cara: admin mengisi di Admin → Menu, atau kolom `price` di MENU diisi
 * lalu `--sync` dijalankan. Harga yang sudah diisi admin tidak pernah ditimpa.
 * Deskripsi dibuat dari nama/jenis minuman (owner belum memberi) dan bisa diubah admin.
 *
 * Idempoten (SKU SDY-xx). `--sync` menyelaraskan menu yang sudah ada (nama, deskripsi,
 * kategori, pilihan, aktif); `--remove` menghapus menu hasil impor ini.
 */
class RealMenuCommand extends Command
{
    protected $signature = 'sudaya:menu-asli
        {--company=sudaya : Kode company}
        {--sync : Selaraskan menu yang sudah ada dengan data ini (harga dari admin tidak ditimpa)}
        {--remove : Hapus menu asli hasil impor ini}';

    protected $description = 'Memasukkan 22 menu asli Sudaya (aktif, "Harga menyusul" sampai harga diisi)';

    /** Grup pilihan Ice/Hot untuk menu yang bisa disajikan dingin atau panas. */
    public const SERVING_GROUP = 'Penyajian';

    /** Varian Americano Series (keputusan owner 12 November); varian lain bisa request lewat catatan. */
    public const AMERICANO_GROUP = 'Americano Series';

    public const AMERICANO_VARIANTS = ['Original', 'Matcha', 'Sitrus'];

    /** Urutan kategori di menu pelanggan, sama dengan file owner. */
    public const CATEGORIES = ['Coffee Espresso', 'Non Coffee', 'Tea', 'Mocktail', 'Manual Brew'];

    /**
     * Urutan = SKU (SDY-01 …). serve: 'ice_hot' = pilihan Ice/Hot, 'ice' = hanya dingin, null = tanpa pilihan.
     * price: null = harga menyusul (diisi admin).
     *
     * @var list<array{category: string, name: string, serve: ?string, description: string, price: ?int, americano?: bool}>
     */
    public const MENU = [
        ['category' => 'Coffee Espresso', 'name' => 'Americano', 'serve' => 'ice_hot', 'americano' => true, 'price' => null,
            'description' => 'Espresso dengan air: ringan, bersih, dan menyegarkan. Pilih Original atau varian Americano Series (Matcha, Sitrus); varian lain bisa request lewat catatan.'],
        ['category' => 'Coffee Espresso', 'name' => 'Cappuccino', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Espresso dengan susu dan busa susu yang tebal, rasa kopinya seimbang dan lembut.'],
        ['category' => 'Coffee Espresso', 'name' => 'Caffe Latte', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Espresso dengan banyak susu, lembut dengan rasa kopi yang halus.'],
        ['category' => 'Coffee Espresso', 'name' => 'Seasalt Caramel Latte', 'serve' => 'ice', 'price' => null,
            'description' => 'Latte dengan karamel dan sentuhan garam laut: manis, sedikit gurih, dan segar. Disajikan dingin.'],
        ['category' => 'Coffee Espresso', 'name' => 'Butterscotch Latte', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Latte dengan butterscotch yang manis legit beraroma karamel mentega.'],
        ['category' => 'Coffee Espresso', 'name' => 'Hazelnut Latte', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Latte dengan aroma kacang hazelnut yang harum dan manis lembut.'],
        ['category' => 'Coffee Espresso', 'name' => 'Vanilla Latte', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Latte dengan vanila: manis lembut dan wangi.'],
        ['category' => 'Coffee Espresso', 'name' => 'Sudaya Coffee', 'serve' => 'ice', 'price' => null,
            'description' => 'Kopi racikan khas Sudaya Coffee. Disajikan dingin.'],
        ['category' => 'Non Coffee', 'name' => 'Chocolate', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Minuman cokelat yang creamy dan manis, pilihan untuk yang tidak minum kopi.'],
        ['category' => 'Non Coffee', 'name' => 'Chocolate Berry', 'serve' => 'ice', 'price' => null,
            'description' => 'Cokelat berpadu rasa buah berry yang segar. Disajikan dingin.'],
        ['category' => 'Non Coffee', 'name' => 'Maren', 'serve' => 'ice', 'price' => null,
            'description' => 'Matcha + aren: wangi teh hijau dengan manis legit gula aren. Disajikan dingin.'],
        ['category' => 'Non Coffee', 'name' => 'Matcha Berry', 'serve' => 'ice', 'price' => null,
            'description' => 'Matcha berpadu rasa buah berry, segar dengan sedikit asam. Disajikan dingin.'],
        ['category' => 'Non Coffee', 'name' => 'Matcha Latte', 'serve' => 'ice_hot', 'price' => null,
            'description' => 'Matcha dengan susu: lembut dengan rasa teh hijau yang khas.'],
        ['category' => 'Non Coffee', 'name' => 'Strawberry Manis', 'serve' => 'ice', 'price' => null,
            'description' => 'Minuman stroberi yang manis dan segar. Disajikan dingin.'],
        ['category' => 'Tea', 'name' => 'Sweet Tea', 'serve' => null, 'price' => null,
            'description' => 'Teh manis klasik yang menyegarkan.'],
        ['category' => 'Tea', 'name' => 'Lemon Tea', 'serve' => null, 'price' => null,
            'description' => 'Teh dengan perasan lemon, segar dan sedikit asam.'],
        ['category' => 'Tea', 'name' => 'Lychee Tea', 'serve' => null, 'price' => null,
            'description' => 'Teh dengan rasa leci yang manis dan wangi.'],
        ['category' => 'Tea', 'name' => 'Thai Tea', 'serve' => null, 'price' => null,
            'description' => 'Teh ala Thailand dengan susu, manis dan creamy.'],
        ['category' => 'Mocktail', 'name' => 'Lemonade Telang', 'serve' => null, 'price' => null,
            'description' => 'Lemonade segar dengan bunga telang yang memberi warna biru keunguan alami.'],
        ['category' => 'Mocktail', 'name' => 'Twilight', 'serve' => null, 'price' => null,
            'description' => 'Mocktail segar racikan Sudaya Coffee.'],
        ['category' => 'Manual Brew', 'name' => 'V60', 'serve' => null, 'price' => null,
            'description' => 'Kopi seduh manual metode V60 (pour over): rasanya bersih dan karakter biji kopinya lebih terasa.'],
        ['category' => 'Manual Brew', 'name' => 'Japanese Iced Coffee', 'serve' => null, 'price' => null,
            'description' => 'Kopi seduh manual ala Jepang: diseduh panas langsung di atas es, rasanya bersih dan segar.'],
    ];

    public static function sku(int $index): string
    {
        return sprintf('SDY-%02d', $index + 1);
    }

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
        if ($existing()->exists() && ! $this->option('sync')) {
            $this->info('Menu asli sudah dimasukkan. Pakai --sync untuk menyelaraskan nama, deskripsi, dan pilihan.');

            return self::SUCCESS;
        }

        // Satu transaksi: gagal di tengah → tidak ada menu setengah jadi.
        $report = DB::transaction(function () use ($brand, $catalog, $actor, $audit) {
            $categories = [];
            foreach (self::CATEGORIES as $i => $name) {
                $row = DB::table('categories')->where('brand_id', $brand->id)->where('name', $name)->whereNull('deleted_at')->first(['id', 'slug', 'sort_order', 'is_active']);
                if ($row === null) {
                    $categories[$name] = $catalog->createCategory($actor, $brand->id, ['name' => $name, 'sortOrder' => $i + 1], $audit)['id'];

                    continue;
                }
                if ((int) $row->sort_order !== $i + 1 || ! $row->is_active) {
                    $catalog->updateCategory($actor, $row->id, ['name' => $name, 'slug' => $row->slug, 'sortOrder' => $i + 1, 'isActive' => true], $audit);
                }
                $categories[$name] = $row->id;
            }
            $group = fn (string $name, array $options) => DB::table('modifier_groups')->where('brand_id', $brand->id)->where('name', $name)->value('id')
                ?? $catalog->createModifierGroup($actor, $brand->id, [
                    'name' => $name, 'selection' => 'single', 'isRequired' => true, 'minSelect' => 1, 'maxSelect' => 1,
                    'options' => array_map(fn (string $option, int $i) => ['name' => $option, 'priceDelta' => 0, 'isDefault' => $i === 0], $options, array_keys($options)),
                ], $audit)['id'];
            $serving = $group(self::SERVING_GROUP, ['Ice', 'Hot']);
            $americano = $group(self::AMERICANO_GROUP, self::AMERICANO_VARIANTS);

            $report = ['created' => 0, 'updated' => 0, 'priced' => 0, 'skipped' => []];
            foreach (self::MENU as $i => $item) {
                $sku = self::sku($i);
                $groups = array_values(array_filter([
                    ($item['americano'] ?? false) ? $americano : null,
                    $item['serve'] === 'ice_hot' ? $serving : null,
                ]));
                $current = DB::table('products')->where('brand_id', $brand->id)->where('sku', $sku)->whereNull('deleted_at')->first(['id', 'slug', 'base_price']);
                $payload = [
                    'name' => $item['name'],
                    'sku' => $sku,
                    'categoryId' => $categories[$item['category']],
                    'description' => $item['description'],
                    'isActive' => true,
                    'modifierGroupIds' => $groups,
                ];

                if ($current === null) {
                    if ($this->option('sync') && DB::table('products')->where('brand_id', $brand->id)->where('sku', $sku)->exists()) {
                        // Dihapus admin: tidak dimunculkan lagi.
                        $report['skipped'][] = $item['name'];

                        continue;
                    }
                    // Slug bentrok dengan menu lain (mis. menu contoh) → akhiran -sudaya.
                    $slug = Str::slug($item['name']);
                    if (DB::table('products')->where('brand_id', $brand->id)->where('slug', $slug)->whereNull('deleted_at')->exists()) {
                        $slug .= '-sudaya';
                    }
                    $catalog->createProduct($actor, $brand->id, [...$payload, 'slug' => $slug, 'basePrice' => (string) ($item['price'] ?? 0)], null, $audit);
                    $report['created']++;
                    $report['priced'] += $item['price'] === null ? 0 : 1;

                    continue;
                }

                // Harga dari admin menang; harga di data hanya mengisi yang masih kosong (Rp0).
                $price = (int) $current->base_price;
                if ($item['price'] !== null && $price === 0) {
                    $price = $item['price'];
                    $report['priced']++;
                }
                $catalog->updateProduct($actor, $current->id, [...$payload, 'slug' => $current->slug, 'basePrice' => (string) $price], ['image' => null, 'remove' => false], $audit);
                $report['updated']++;
            }

            return $report;
        });

        $pending = $existing()->where('base_price', '<=', 0)->count();
        $this->info(sprintf(
            'Menu asli: %d dibuat, %d diselaraskan, %d harga dari data. %d menu masih "Harga menyusul" (isi di Admin → Menu).',
            $report['created'], $report['updated'], $report['priced'], $pending,
        ));
        if ($report['skipped'] !== []) {
            $this->line('Dilewati karena sudah dihapus admin: '.implode(', ', $report['skipped']));
        }

        return self::SUCCESS;
    }
}
