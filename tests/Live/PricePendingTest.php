<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Services\Orders\OrderEngine;
use App\Support\Catalog\Pricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keputusan owner 12 November 2026: menu aktif yang harganya belum diisi (Rp0) tampil
 * "Harga menyusul" di menu pelanggan, tetapi belum bisa dipesan di kanal mana pun sampai
 * admin mengisi harganya. Tidak ada pesanan Rp0.
 */
class PricePendingTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $this->app->instance(MenuImageStorage::class, new class implements MenuImageStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void {}

            public function remove(array $paths): void {}
        });
        $A = $ctx->createCompany('hrga');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
        ];
        $admin = $ctx->load($users['adminA']['id']);
        $audit = new AuditContext('127.0.0.1', 'phpunit');
        $catalog = app(CatalogAdmin::class);
        $category = $catalog->createCategory($admin, $A['brandId'], ['name' => 'Coffee'], $audit)['id'];
        $product = fn (string $name, int $price) => $catalog->createProduct($admin, $A['brandId'], [
            'name' => $name, 'categoryId' => $category, 'basePrice' => (string) $price, 'isActive' => true, 'modifierGroupIds' => [],
            'description' => 'Menu uji.',
        ], null, $audit)['id'];

        return ['A' => $A, ...$users, 'category' => $category, 'pending' => $product('Kopi Harga Menyusul', 0), 'priced' => $product('Kopi Berharga', 20000)];
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function order(string $channel, array $lines): array
    {
        $outlet = $channel === 'pos' ? ['id' => static::$fixture['A']['outletId']] : ['code' => static::$fixture['A']['outletCode']];
        $actor = $channel === 'pos' ? $this->ctx()->load(static::$fixture['cashierA']['id']) : null;

        return app(OrderEngine::class)->create($channel, $outlet, $actor, [
            'idempotencyKey' => (string) Str::uuid(), 'type' => 'takeaway', 'customerName' => 'Uji', 'department' => 'Fundraising', 'paymentMethod' => 'cash',
            'lines' => array_map(fn (string $key) => ['productId' => static::$fixture[$key], 'modifierIds' => [], 'quantity' => 1], $lines),
        ], $this->audit());
    }

    private function orderCount(): int
    {
        return DB::table('orders')->where('company_id', static::$fixture['A']['id'])->count();
    }

    public function test_menu_tanpa_harga_tampil_di_menu_pelanggan(): void
    {
        $menu = app(Storefront::class)->load(static::$fixture['A']['outletCode']);
        $product = collect($menu['products'])->firstWhere('id', static::$fixture['pending']);

        $this->assertNotNull($product, 'menu aktif tanpa harga tetap tampil');
        $this->assertSame([0, true, 'Menu uji.'], [$product['price'], $product['isAvailable'], $product['description']]);
    }

    public function test_semua_kanal_menolak_menu_tanpa_harga_lalu_bisa_dipesan_setelah_harga_diisi(): void
    {
        $before = $this->orderCount();
        foreach (['qr_counter', 'website', 'kiosk', 'pos'] as $channel) {
            foreach ([['pending'], ['priced', 'pending']] as $lines) {
                try {
                    $this->order($channel, $lines);
                    $this->fail("{$channel}: pesanan menu tanpa harga harus ditolak");
                } catch (AccessException $error) {
                    $this->assertSame('invalid', $error->reason, $channel);
                    $this->assertSame('Kopi Harga Menyusul: '.Pricing::PRICE_PENDING_ERROR, $error->getMessage(), $channel);
                }
            }
        }
        $this->assertSame($before, $this->orderCount(), 'tidak ada pesanan yang tersimpan');

        // Menu berharga di keranjang yang sama tetap bisa dipesan sendiri.
        $this->assertSame(20000, (int) DB::table('orders')->where('id', $this->order('website', ['priced'])['orderId'])->value('total'));

        // Admin mengisi harga → langsung bisa dipesan, harga dihitung server.
        $admin = $this->ctx()->load(static::$fixture['adminA']['id']);
        $current = DB::table('products')->where('id', static::$fixture['pending'])->first();
        app(CatalogAdmin::class)->updateProduct($admin, $current->id, [
            'name' => $current->name, 'slug' => $current->slug, 'categoryId' => static::$fixture['category'], 'basePrice' => '18000',
            'description' => $current->description, 'isActive' => true, 'modifierGroupIds' => [],
        ], ['image' => null, 'remove' => false], $this->audit());
        $id = $this->order('pos', ['pending'])['orderId'];
        $this->assertSame(18000, (int) DB::table('orders')->where('id', $id)->value('total'));
    }
}
