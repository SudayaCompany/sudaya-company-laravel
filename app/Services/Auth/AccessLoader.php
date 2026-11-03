<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Access\AccessGrant;
use Illuminate\Support\Facades\DB;

/** Memuat identitas dan hak akses user dari DB. */
final class AccessLoader
{
    public function currentUser(string $userId): ?User
    {
        return User::query()
            ->with('company')
            ->leftJoin('auth.users as auth_user', 'auth_user.id', '=', 'users.id')
            ->select('users.*', 'auth_user.email', 'auth_user.banned_until')
            ->where('users.id', $userId)
            ->first();
    }

    /** @return list<AccessGrant> */
    public function grants(string $userId, string $companyId): array
    {
        $rows = DB::table('user_roles')
            // Role & outlet wajib milik company user: assignment lintas company diabaikan.
            ->join('roles', function ($join) use ($companyId) {
                $join->on('roles.id', '=', 'user_roles.role_id')->where('roles.company_id', '=', $companyId);
            })
            ->leftJoin('outlets', 'outlets.id', '=', 'user_roles.outlet_id')
            ->leftJoin('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $userId)
            ->where(fn ($query) => $query->whereNull('user_roles.outlet_id')->orWhere('outlets.company_id', $companyId))
            ->select(
                'user_roles.id as user_role_id',
                'roles.id as role_id',
                'roles.code as role_code',
                'roles.name as role_name',
                'user_roles.outlet_id',
                'role_permissions.permission_code',
            )
            ->get();

        $grants = [];
        foreach ($rows->groupBy('user_role_id') as $id => $group) {
            $first = $group->first();
            $grants[] = new AccessGrant(
                $id,
                $first->role_id,
                $first->role_code,
                $first->role_name,
                $first->outlet_id,
                $group->pluck('permission_code')->filter()->values()->all(),
            );
        }

        return $grants;
    }

    /**
     * JWT Supabase diverifikasi secara lokal, jadi token tetap "valid" sampai
     * kedaluwarsa walau user sudah logout. Cek ke auth.sessions agar logout dan
     * pencabutan session langsung berlaku.
     */
    public function isSessionActive(string $sessionId, string $userId): bool
    {
        return DB::selectOne(
            'select 1 as active from auth.sessions
             where id = ? and user_id = ? and (not_after is null or not_after > now())
             limit 1',
            [$sessionId, $userId],
        ) !== null;
    }

    /**
     * Outlet yang boleh diakses user: semua (grant company-wide) atau yang di-assign.
     *
     * @return list<array{id: string, code: string, name: string, brandId: string, brandName: string, timezone: string}>
     */
    public function accessibleOutlets(User $user): array
    {
        $outletIds = array_values(array_unique(array_filter(array_map(
            fn (AccessGrant $grant) => $grant->outletId,
            $user->grants(),
        ))));
        $companyWide = array_any($user->grants(), fn (AccessGrant $grant) => $grant->outletId === null);
        if (! $companyWide && $outletIds === []) {
            return [];
        }

        return DB::table('outlets')
            ->join('brands', 'brands.id', '=', 'outlets.brand_id')
            ->where('outlets.company_id', $user->company_id)
            ->where('outlets.is_active', true)
            ->when(! $companyWide, fn ($query) => $query->whereIn('outlets.id', $outletIds))
            ->orderBy('outlets.code')
            ->get(['outlets.id', 'outlets.code', 'outlets.name', 'brands.id as brand_id', 'brands.name as brand_name', 'outlets.timezone'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'brandId' => $row->brand_id,
                'brandName' => $row->brand_name,
                'timezone' => $row->timezone,
            ])
            ->all();
    }
}
