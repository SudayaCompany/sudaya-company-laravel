<?php

namespace Tests\Unit;

use App\Support\Access\Rbac;
use PHPUnit\Framework\TestCase;

/** Padanan tests/unit/rbac.test.ts. */
class RbacTest extends TestCase
{
    private static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, Rbac::DEFAULT_ROLE_PERMISSIONS[$role], true);
    }

    public function test_memuat_41_permission_unik_dengan_format_domain_aksi(): void
    {
        $codes = Rbac::codes();
        $this->assertCount(41, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $code);
        }
    }

    public function test_memuat_6_role_internal(): void
    {
        $this->assertSame(['super_admin', 'owner', 'manager', 'admin', 'barista', 'cashier'], array_keys(Rbac::ROLES));
    }

    public function test_mapping_default_hanya_merujuk_permission_yang_ada_tanpa_duplikat(): void
    {
        foreach (Rbac::DEFAULT_ROLE_PERMISSIONS as $permissions) {
            $this->assertSame($permissions, array_values(array_unique($permissions)));
            foreach ($permissions as $permission) {
                $this->assertContains($permission, Rbac::codes());
            }
        }
    }

    public function test_izin_administrasi_sistem_hanya_untuk_super_admin(): void
    {
        foreach (array_keys(Rbac::DEFAULT_ROLE_PERMISSIONS) as $role) {
            foreach (['user.manage', 'role.manage', 'settings.manage'] as $permission) {
                $this->assertFalse(self::roleHas($role, $permission), "{$role} {$permission}");
            }
        }
    }

    public function test_owner_akses_bisnis_dan_laporan_bukan_operasi_kasir(): void
    {
        foreach (['dashboard.view', 'report.sales', 'approval.approve'] as $permission) {
            $this->assertTrue(self::roleHas('owner', $permission));
        }
        $this->assertFalse(self::roleHas('owner', 'payment.confirm_cash'));
        $this->assertFalse(self::roleHas('owner', 'cash.open'));
    }

    public function test_manager_operasional_outlet_dan_approval(): void
    {
        foreach (['approval.approve', 'inventory.adjust', 'cash.close', 'kds.manage'] as $permission) {
            $this->assertTrue(self::roleHas('manager', $permission));
        }
        $this->assertFalse(self::roleHas('manager', 'audit.view'));
    }

    public function test_barista_tanpa_kas_approval_dan_admin(): void
    {
        foreach (['attendance.checkin', 'order.create', 'kds.view', 'inventory.view'] as $permission) {
            $this->assertTrue(self::roleHas('barista', $permission));
        }
        foreach (['dashboard.view', 'payment.confirm_cash', 'cash.open', 'kds.manage', 'approval.approve', 'inventory.adjust'] as $permission) {
            $this->assertFalse(self::roleHas('barista', $permission));
        }
    }

    public function test_kasir_pos_pembayaran_sesi_kas_tanpa_kds(): void
    {
        foreach (['order.create', 'payment.confirm_cash', 'cash.open', 'cash.close'] as $permission) {
            $this->assertTrue(self::roleHas('cashier', $permission));
        }
        $this->assertFalse(self::roleHas('cashier', 'kds.view'));
        $this->assertFalse(self::roleHas('cashier', 'dashboard.view'));
    }

    public function test_setiap_role_selain_super_admin_subset_sejati(): void
    {
        foreach (Rbac::DEFAULT_ROLE_PERMISSIONS as $permissions) {
            $this->assertLessThan(count(Rbac::codes()), count($permissions));
        }
    }

    /** Katalog PHP harus sama persis dengan baseline Next.js agar data role di DB tetap berlaku. */
    public function test_sama_dengan_katalog_nextjs(): void
    {
        $source = @file_get_contents('C:/magang/SudayaCoffee/src/lib/auth/rbac.ts');
        if ($source === false) {
            $this->markTestSkipped('Repo Next.js tidak ada di mesin ini.');
        }
        preg_match('/PERMISSIONS = \{(.*?)\} as const/s', $source, $block);
        preg_match_all('/"([a-z_]+\.[a-z_]+)": "([^"]+)"/', $block[1], $pairs, PREG_SET_ORDER);
        $this->assertSame(array_column($pairs, 2, 1), Rbac::PERMISSIONS);

        preg_match('/DEFAULT_ROLE_PERMISSIONS:.*?= \{(.*?)\n\};/s', $source, $defaults);
        foreach (Rbac::DEFAULT_ROLE_PERMISSIONS as $role => $permissions) {
            preg_match('/\n  '.$role.': \[(.*?)\]/s', $defaults[1], $list);
            preg_match_all('/"([a-z_]+\.[a-z_]+)"/', $list[1], $codes);
            $this->assertSame($codes[1], $permissions, $role);
        }
    }
}
