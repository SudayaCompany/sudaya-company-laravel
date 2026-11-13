<?php

namespace Tests\Live;

use App\Services\Seed\InitialData;
use App\Support\Access\Rbac;
use Illuminate\Support\Facades\DB;

/** Padanan tests/integration/seed.test.ts. */
class SeedTest extends LiveTestCase
{
    private function counts(): array
    {
        return array_map(fn (string $table) => DB::table($table)->count(), [
            'companies' => 'companies',
            'brands' => 'brands',
            'outlets' => 'outlets',
            'roles' => 'roles',
            'permissions' => 'permissions',
            'role_permissions' => 'role_permissions',
        ]);
    }

    public function test_seed_dijalankan_dua_kali_tidak_membuat_duplikat(): void
    {
        $seed = app(InitialData::class);
        $seed->seed();
        $before = $this->counts();
        $report = $seed->seed();

        $this->assertSame($before, $this->counts());
        $this->assertSame([
            'company' => 'existing',
            'brand' => 'existing',
            'outlet' => 'existing',
            'permissionsCreated' => 0,
            'rolesCreated' => [],
            'rolePermissionsCreated' => 0,
        ], $report);
    }

    public function test_data_awal_sudaya_valid(): void
    {
        $row = DB::table('outlets')
            ->join('brands', 'brands.id', '=', 'outlets.brand_id')
            ->join('companies', 'companies.id', '=', 'outlets.company_id')
            ->where('outlets.code', 'SDY01')
            ->first(['companies.name as company', 'brands.name as brand', 'outlets.name as outlet', 'outlets.attendance_radius_meter as radius', 'brands.company_id as brand_company', 'outlets.company_id as outlet_company', 'companies.id as company_id']);

        $this->assertSame('Sudaya Company', $row->company);
        $this->assertSame('Sudaya Coffee', $row->brand);
        $this->assertSame('Sudaya Coffee — Outlet 01', $row->outlet);
        $this->assertGreaterThan(0, $row->radius);
        $this->assertSame($row->company_id, $row->brand_company);
        $this->assertSame($row->company_id, $row->outlet_company);
    }

    public function test_tidak_mereset_pengaturan_dan_tidak_mengembalikan_permission_yang_dicabut(): void
    {
        $company = $this->ctx()->createCompany('seed');
        DB::table('outlets')->where('id', $company['outletId'])->update(['attendance_radius_meter' => 25]);
        $revoked = ['role_id' => $company['roles']['barista'], 'permission_code' => 'attendance.checkout'];
        DB::table('role_permissions')->where($revoked)->delete();

        $report = app(InitialData::class)->seed($company['target']);

        $this->assertSame('existing', $report['outlet']);
        $this->assertSame(0, $report['rolePermissionsCreated']);
        $this->assertSame(25, DB::table('outlets')->where('id', $company['outletId'])->value('attendance_radius_meter'));
        $this->assertFalse(DB::table('role_permissions')->where($revoked)->exists());
    }

    public function test_company_baru_mendapat_role_dan_permission_default(): void
    {
        $company = $this->ctx()->createCompany('defaults');

        $this->assertCount(6, $company['roles']);
        $this->assertSame(41, DB::table('role_permissions')->where('role_id', $company['roles']['super_admin'])->count());
        $this->assertSame(14, DB::table('role_permissions')->where('role_id', $company['roles']['cashier'])->count());
        // Keputusan owner 12 November 2026: kasir = pusat pesanan (+ Pesanan masuk, status, batal), barista = absen saja.
        $codes = fn (string $role) => DB::table('role_permissions')->where('role_id', $company['roles'][$role])->pluck('permission_code')->all();
        $this->assertEqualsCanonicalizing(Rbac::DEFAULT_ROLE_PERMISSIONS['cashier'], $codes('cashier'));
        $this->assertEqualsCanonicalizing(['attendance.checkin', 'attendance.checkout'], $codes('barista'));
    }
}
