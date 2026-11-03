<?php

namespace App\Services\Seed;

use App\Support\Access\Rbac;
use Illuminate\Support\Facades\DB;

/**
 * Seeder idempotent: hanya INSERT ... ON CONFLICT DO NOTHING, tidak pernah
 * UPDATE atau DELETE, sehingga pengaturan dan kustomisasi yang ada tidak tereset.
 */
final class InitialData
{
    public const TARGET = [
        'company' => ['code' => 'sudaya', 'name' => 'Sudaya Company'],
        'brand' => ['code' => 'sudaya-coffee', 'name' => 'Sudaya Coffee'],
        // Dipakai di URL QR: /order?outlet=SDY01 (PRD §110). Alamat dan koordinat diisi Super Admin nanti.
        'outlet' => ['code' => 'SDY01', 'name' => 'Sudaya Coffee — Outlet 01'],
    ];

    /** Target bisa diganti (dipakai test untuk company sementara). */
    public function seed(array $target = self::TARGET): array
    {
        return DB::transaction(function () use ($target) {
            // Cegah dua seeder berjalan bersamaan.
            DB::select("select pg_advisory_xact_lock(hashtext('sudaya:seed'))");

            $createdCompany = DB::table('companies')->insertOrIgnore($target['company']);
            $companyId = DB::table('companies')->where('code', $target['company']['code'])->value('id');

            $createdBrand = DB::table('brands')->insertOrIgnore([...$target['brand'], 'company_id' => $companyId]);
            $brandId = DB::table('brands')
                ->where('company_id', $companyId)
                ->where('code', $target['brand']['code'])
                ->value('id');

            $createdOutlet = DB::table('outlets')->insertOrIgnore([
                ...$target['outlet'],
                'company_id' => $companyId,
                'brand_id' => $brandId,
            ]);

            $createdPermissions = DB::table('permissions')->insertOrIgnore(array_map(
                fn (string $code) => ['code' => $code, 'description' => Rbac::PERMISSIONS[$code]],
                Rbac::codes(),
            ));

            $placeholders = implode(', ', array_fill(0, count(Rbac::ROLES), '(?::uuid, ?, ?)'));
            $bindings = [];
            foreach (Rbac::ROLES as $code => $name) {
                array_push($bindings, $companyId, $code, $name);
            }
            $createdRoles = DB::select(
                "insert into roles (company_id, code, name) values {$placeholders}
                 on conflict (company_id, code) do nothing returning id, code",
                $bindings,
            );

            // Super Admin selalu mendapat semua permission (termasuk permission baru).
            // Role lain hanya diberi default saat role itu baru dibuat, supaya
            // permission yang sengaja dicabut admin tidak dikembalikan oleh seeder.
            $superAdminId = DB::table('roles')
                ->where('company_id', $companyId)
                ->where('code', Rbac::SUPER_ADMIN_ROLE)
                ->value('id');
            $mapping = array_map(
                fn (string $code) => ['role_id' => $superAdminId, 'permission_code' => $code],
                Rbac::codes(),
            );
            foreach ($createdRoles as $role) {
                foreach (Rbac::DEFAULT_ROLE_PERMISSIONS[$role->code] ?? [] as $code) {
                    $mapping[] = ['role_id' => $role->id, 'permission_code' => $code];
                }
            }
            $createdMappings = DB::table('role_permissions')->insertOrIgnore($mapping);

            return [
                'company' => $createdCompany ? 'created' : 'existing',
                'brand' => $createdBrand ? 'created' : 'existing',
                'outlet' => $createdOutlet ? 'created' : 'existing',
                'permissionsCreated' => $createdPermissions,
                'rolesCreated' => array_map(fn ($role) => $role->code, $createdRoles),
                'rolePermissionsCreated' => $createdMappings,
            ];
        });
    }
}
