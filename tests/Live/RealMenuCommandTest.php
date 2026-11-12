<?php

namespace Tests\Live;

use App\Console\Commands\RealMenuCommand;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan sudaya:menu-asli` di company kosong (seperti production baru) dan `--sync`:
 * 22 menu aktif "Harga menyusul", kategori urut file owner, Americano Series + Ice/Hot,
 * harga yang sudah diisi admin tidak ditimpa, menu yang dihapus admin tidak dimunculkan lagi.
 */
class RealMenuCommandTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('mnua');

        return ['A' => $A, 'adminA' => $ctx->createUser($A, [['role' => 'super_admin']])];
    }

    private function products(): Collection
    {
        return DB::table('products')->where('brand_id', static::$fixture['A']['brandId'])->whereNull('deleted_at')->orderBy('sku')->get();
    }

    public function test_impor_baru_lalu_sync_tanpa_menimpa_harga_admin(): void
    {
        $code = static::$fixture['A']['code'];
        $this->artisan('sudaya:menu-asli', ['--company' => $code])->assertSuccessful();

        $products = $this->products();
        $this->assertCount(22, $products);
        $this->assertSame(22, $products->where('is_active', true)->count(), 'semua aktif');
        $this->assertSame(0, $products->where('base_price', '>', 0)->count(), 'harga menyusul');
        $this->assertSame(array_column(RealMenuCommand::MENU, 'name'), $products->pluck('name')->all());

        $menu = app(Storefront::class)->load(static::$fixture['A']['outletCode']);
        $this->assertSame(RealMenuCommand::CATEGORIES, array_column($menu['categories'], 'name'), 'urut sesuai file owner');
        $americano = collect($menu['products'])->firstWhere('name', 'Americano');
        $this->assertSame(
            ['Americano Series' => ['Original', 'Matcha', 'Sitrus'], 'Penyajian' => ['Ice', 'Hot']],
            collect($americano['groups'])->mapWithKeys(fn ($g) => [$g['name'] => array_column($g['modifiers'], 'name')])->all(),
        );
        $this->assertCount(2, $americano['defaultModifierIds'], 'Original dan Ice terpilih otomatis');
        $this->assertSame([], collect($menu['products'])->firstWhere('name', 'V60')['groups']);

        // Admin mengisi harga Cappuccino dan menghapus Twilight; menu lain diubah namanya.
        $admin = $this->ctx()->load(static::$fixture['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $cappuccino = $products->firstWhere('name', 'Cappuccino');
        app(CatalogAdmin::class)->updateProduct($admin, $cappuccino->id, [
            'name' => 'Cappuccino', 'slug' => $cappuccino->slug, 'sku' => $cappuccino->sku, 'categoryId' => $cappuccino->category_id,
            'basePrice' => '24000', 'description' => $cappuccino->description, 'isActive' => true, 'modifierGroupIds' => [],
        ], ['image' => null, 'remove' => false], $audit);
        app(CatalogAdmin::class)->deleteProduct($admin, $products->firstWhere('name', 'Twilight')->id, $audit);
        DB::table('products')->where('id', $products->firstWhere('name', 'Maren')->id)->update(['name' => 'Maren Lama']);

        $this->artisan('sudaya:menu-asli', ['--company' => $code])->expectsOutputToContain('sudah dimasukkan')->assertSuccessful();
        $this->artisan('sudaya:menu-asli', ['--company' => $code, '--sync' => true])
            ->expectsOutputToContain('Dilewati karena sudah dihapus admin: Twilight')->assertSuccessful();

        $after = $this->products();
        $this->assertCount(21, $after, 'menu yang dihapus admin tidak dimunculkan lagi');
        $this->assertSame(24000, (int) $after->firstWhere('name', 'Cappuccino')->base_price, 'harga dari admin tidak ditimpa');
        $this->assertNotNull($after->firstWhere('name', 'Maren'), 'nama diselaraskan lagi');
        $this->assertSame(1, DB::table('modifier_groups')->where('brand_id', static::$fixture['A']['brandId'])->where('name', 'Americano Series')->count(), 'grup tidak dobel');
    }
}
