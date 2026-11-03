<?php

namespace App\Services\Auth;

use App\Services\Audit\AuditLogger;
use App\Support\Access\Rbac;
use Illuminate\Support\Facades\DB;

final class AdminBootstrap
{
    private const ALREADY_HAS_ADMIN = 'Company ini sudah memiliki Super Admin. Tambahkan admin lain lewat menu Pengguna di /admin/users.';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Cek awal sebelum membuat akun di Supabase Auth, agar tidak tercipta akun
     * yatim saat bootstrap pasti ditolak. Pengecekan final tetap di dalam transaksi.
     */
    public function assertAllowed(string $companyCode, ?string $authUserId = null): void
    {
        $admins = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('companies', 'companies.id', '=', 'roles.company_id')
            ->where('companies.code', $companyCode)
            ->where('roles.code', Rbac::SUPER_ADMIN_ROLE)
            ->whereNull('user_roles.outlet_id')
            ->pluck('user_roles.user_id');

        if ($admins->isNotEmpty() && ! $admins->contains($authUserId)) {
            throw new BootstrapRefused(self::ALREADY_HAS_ADMIN);
        }
    }

    /**
     * Menjadikan akun Supabase Auth yang sudah ada sebagai Super Admin pertama
     * sebuah company. Menolak jika company sudah punya Super Admin lain, supaya
     * perintah ini tidak bisa dipakai untuk memperluas hak akses akun lain.
     *
     * @return array{status: 'created'|'already_super_admin', companyName: string}
     */
    public function run(string $companyCode, string $authUserId, string $fullName): array
    {
        return DB::transaction(function () use ($companyCode, $authUserId, $fullName) {
            DB::select("select pg_advisory_xact_lock(hashtext('sudaya:admin-bootstrap'))");

            $company = DB::table('companies')->where('code', $companyCode)->first(['id', 'name']);
            if ($company === null) {
                throw new BootstrapRefused("Company \"{$companyCode}\" belum ada. Jalankan \"php artisan db:seed\" dulu.");
            }

            $roleId = DB::table('roles')
                ->where('company_id', $company->id)
                ->where('code', Rbac::SUPER_ADMIN_ROLE)
                ->value('id');
            if ($roleId === null) {
                throw new BootstrapRefused('Role '.Rbac::SUPER_ADMIN_ROLE.' belum ada. Jalankan "php artisan db:seed" dulu.');
            }

            $admins = DB::table('user_roles')->where('role_id', $roleId)->whereNull('outlet_id')->pluck('user_id');
            if ($admins->contains($authUserId)) {
                return ['status' => 'already_super_admin', 'companyName' => $company->name];
            }
            if ($admins->isNotEmpty()) {
                throw new BootstrapRefused(self::ALREADY_HAS_ADMIN);
            }

            $existing = DB::table('users')->where('id', $authUserId)->first(['company_id', 'is_active']);
            if ($existing !== null && $existing->company_id !== $company->id) {
                throw new BootstrapRefused('Akun ini sudah terdaftar di company lain.');
            }
            if ($existing !== null && ! $existing->is_active) {
                throw new BootstrapRefused('Akun ini berstatus nonaktif.');
            }

            if ($existing === null) {
                DB::table('users')->insert(['id' => $authUserId, 'company_id' => $company->id, 'full_name' => $fullName]);
            }
            DB::table('user_roles')->insertOrIgnore(['user_id' => $authUserId, 'role_id' => $roleId, 'outlet_id' => null]);

            $this->audit->record([
                'company_id' => $company->id,
                'actor_id' => null,
                'action' => 'auth.bootstrap_super_admin',
                'entity_type' => 'user',
                'entity_id' => $authUserId,
                'after' => ['role' => Rbac::SUPER_ADMIN_ROLE, 'outletId' => null],
                'reason' => 'php artisan sudaya:admin-bootstrap',
            ]);

            return ['status' => 'created', 'companyName' => $company->name];
        });
    }
}
