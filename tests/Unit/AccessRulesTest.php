<?php

namespace Tests\Unit;

use App\Support\Access\AccessGrant;
use App\Support\Access\AccessRules;
use App\Support\Access\AccessScope;
use App\Support\Access\Rbac;
use App\Support\Access\RouteAccess;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Padanan tests/unit/policy.test.ts. */
class AccessRulesTest extends TestCase
{
    private const OUTLET_A = '0b4f2a52-6d39-4b5e-9a57-1c0f6f4c8a01';

    private const OUTLET_B = '6a1d3f0e-2c7b-4a8e-8f61-5d9e2b7c4a02';

    private static function grant(string $role, ?string $outletId = null): AccessGrant
    {
        return new AccessGrant(
            (string) Str::uuid(),
            (string) Str::uuid(),
            $role,
            $role,
            $outletId,
            $role === 'super_admin' ? Rbac::codes() : Rbac::DEFAULT_ROLE_PERMISSIONS[$role],
        );
    }

    public function test_grant_outlet_berlaku_hanya_di_outlet_tsb(): void
    {
        $cashier = [self::grant('cashier', self::OUTLET_A)];
        $this->assertTrue(AccessRules::hasPermission($cashier, 'order.create'));
        $this->assertTrue(AccessRules::hasPermission($cashier, 'order.create', AccessScope::outlet(self::OUTLET_A)));
        $this->assertFalse(AccessRules::hasPermission($cashier, 'order.create', AccessScope::outlet(self::OUTLET_B)));
    }

    public function test_grant_outlet_tidak_memenuhi_syarat_company_wide(): void
    {
        $this->assertFalse(AccessRules::hasPermission([self::grant('cashier', self::OUTLET_A)], 'order.create', AccessScope::company()));
    }

    public function test_grant_company_wide_berlaku_di_semua_outlet(): void
    {
        $owner = [self::grant('owner')];
        $this->assertTrue(AccessRules::hasPermission($owner, 'report.sales', AccessScope::outlet(self::OUTLET_B)));
        $this->assertTrue(AccessRules::hasPermission($owner, 'report.sales', AccessScope::company()));
    }

    public function test_user_tanpa_grant_tidak_punya_permission(): void
    {
        $this->assertFalse(AccessRules::hasPermission([], 'order.view'));
    }

    public function test_super_admin_mendominasi_semua_role(): void
    {
        $this->assertTrue(AccessRules::dominates([self::grant('super_admin')], [self::grant('manager')]));
    }

    public function test_manager_tidak_mendominasi_owner(): void
    {
        $this->assertFalse(AccessRules::dominates([self::grant('manager')], [self::grant('owner')]));
    }

    public function test_manager_outlet_a_mendominasi_barista_hanya_pada_scope_outlet_a(): void
    {
        $manager = [self::grant('manager', self::OUTLET_A)];
        $barista = [self::grant('barista', self::OUTLET_A)];
        $this->assertTrue(AccessRules::dominates($manager, $barista, AccessScope::outlet(self::OUTLET_A)));
        $this->assertFalse(AccessRules::dominates($manager, $barista, AccessScope::outlet(self::OUTLET_B)));
        $this->assertFalse(AccessRules::dominates($manager, $barista));
    }

    public static function safePaths(): array
    {
        return [['/admin'], ['/admin/users?tab=1#top'], ['/staff']];
    }

    #[DataProvider('safePaths')]
    public function test_safe_next_menerima_path_internal(string $path): void
    {
        $this->assertSame($path, RouteAccess::safeNext($path));
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($value) => [$value], [
            '//evil.com',
            '//evil.com/admin',
            '/\\evil.com',
            '/\\/evil.com',
            "/\t/evil.com",
            'https://evil.com',
            'http:/evil.com',
            'javascript:alert(1)',
            'evil.com',
            '',
            '/login',
            '/login?next=/admin',
            '/auth/confirm?token_hash=x',
            '/'.str_repeat('a', 600),
            '/admin/../login',
            '/admin/%2e%2e/login',
            "/admin\n",
        ]);
    }

    #[DataProvider('unsafePaths')]
    public function test_safe_next_menolak(string $path): void
    {
        $this->assertNull(RouteAccess::safeNext($path));
    }

    public function test_safe_next_menolak_non_string(): void
    {
        $this->assertNull(RouteAccess::safeNext(null));
        $this->assertNull(RouteAccess::safeNext(['/admin']));
    }

    public function test_landing_sesuai_akses_yang_dimiliki(): void
    {
        $this->assertSame('/admin', RouteAccess::landing([self::grant('super_admin')]));
        $this->assertSame('/admin', RouteAccess::landing([self::grant('owner')]));
        // Satu pintu /login (keputusan owner 12 November 2026): barista → absen, kasir → pesanan masuk.
        $this->assertSame('/barista', RouteAccess::landing([self::grant('barista', self::OUTLET_A)]));
        $this->assertSame('/kds', RouteAccess::landing([self::grant('cashier', self::OUTLET_A)]));
        $this->assertNull(RouteAccess::landing([]));
    }

    public function test_barista_hanya_absen_tanpa_pesanan_dan_admin(): void
    {
        $barista = [self::grant('barista', self::OUTLET_A)];
        $this->assertFalse(RouteAccess::canAccess($barista, '/admin'));
        $this->assertFalse(RouteAccess::canAccess($barista, '/admin/users'));
        $this->assertTrue(RouteAccess::canAccess($barista, '/staff'));
        $this->assertTrue(RouteAccess::canAccess($barista, '/barista'));
        $this->assertTrue(RouteAccess::canAccess($barista, '/staff/attendance'));
        $this->assertFalse(RouteAccess::canAccess($barista, '/kds'), 'pesanan hanya lewat kasir agar tidak dobel');
        $this->assertFalse(RouteAccess::canAccess($barista, '/kds/alarm'));
        $this->assertFalse(RouteAccess::canAccess($barista, '/pos'));
        $this->assertFalse(RouteAccess::canAccess($barista, '/pos/orders'));
    }

    public function test_kasir_pusat_pesanan_tanpa_area_admin(): void
    {
        $cashier = [self::grant('cashier', self::OUTLET_A)];
        $this->assertTrue(RouteAccess::canAccess($cashier, '/kds'));
        // Umpan alarm pesanan baru untuk semua halaman kasir.
        $this->assertTrue(RouteAccess::canAccess($cashier, '/kds/alarm'));
        $this->assertTrue(RouteAccess::canAccess($cashier, '/pos'));
        $this->assertTrue(RouteAccess::canAccess($cashier, '/pos/orders'));
        $this->assertFalse(RouteAccess::canAccess($cashier, '/admin'));
    }

    public function test_owner_dashboard_dan_audit_tanpa_manajemen_user_role(): void
    {
        $owner = [self::grant('owner')];
        $this->assertTrue(RouteAccess::canAccess($owner, '/admin'));
        $this->assertTrue(RouteAccess::canAccess($owner, '/admin/audit'));
        $this->assertFalse(RouteAccess::canAccess($owner, '/admin/users'));
        $this->assertFalse(RouteAccess::canAccess($owner, '/admin/roles'));
    }

    public function test_prefix_terpanjang_menang_dan_tidak_salah_cocok(): void
    {
        // Role khusus dengan inventory.issue saja: stok keluar boleh, stok masuk (prefix lebih panjang) tidak.
        $issuer = [new AccessGrant((string) Str::uuid(), (string) Str::uuid(), 'custom', 'Pencatat waste', self::OUTLET_A, ['inventory.issue'])];
        $this->assertTrue(RouteAccess::canAccess($issuer, '/admin/inventory/stock-out'));
        $this->assertFalse(RouteAccess::canAccess($issuer, '/admin/inventory/stock-in'));
        // Barista (keputusan owner 12 November 2026): hanya absen, tanpa stok/pengeluaran/pesanan.
        $barista = [self::grant('barista', self::OUTLET_A)];
        $this->assertFalse(RouteAccess::canAccess($barista, '/admin/inventory/stock-out'));
        $this->assertFalse(RouteAccess::canAccess($barista, '/admin/expenses'));
        $this->assertTrue(RouteAccess::canAccess($barista, '/staff/attendance/history'));
        // "/adminx" bukan bagian dari "/admin".
        $this->assertSame(null, RouteAccess::requiredFor('/adminx'));
    }

    public function test_post_login_memakai_next_hanya_jika_aman_dan_diizinkan(): void
    {
        $this->assertSame('/admin/users', RouteAccess::postLogin([self::grant('super_admin')], '/admin/users'));
        $this->assertSame('/barista', RouteAccess::postLogin([self::grant('barista', self::OUTLET_A)], '/admin'));
        $this->assertSame('/barista', RouteAccess::postLogin([self::grant('barista', self::OUTLET_A)], '//evil.com'));
        $this->assertSame('/kds', RouteAccess::postLogin([self::grant('cashier', self::OUTLET_A)], null));
        $this->assertNull(RouteAccess::postLogin([], '/admin'));
    }
}
