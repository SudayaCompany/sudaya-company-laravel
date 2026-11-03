<?php

namespace App\Services\Auth;

use App\Exceptions\AccessException;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessScope;
use App\Support\Access\Rbac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Operasi administrasi akses (user, role, permission) dan query halaman admin.
 * Setiap fungsi menerima actor yang sudah terautentikasi dan memverifikasi ulang
 * permission, scope company/outlet, dan aturan anti privilege escalation. Jangan
 * pernah percaya input dari client.
 */
final class AccessAdmin
{
    private const UUID_RULE = ['required', 'uuid'];

    public function __construct(private AuditLogger $audit, private AccessLoader $access) {}

    // --- Queries -----------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listCompanyUsers(User $actor): array
    {
        $this->assertPermission($actor, 'user.manage');

        $rows = DB::table('users')
            ->leftJoin('auth.users as auth_user', 'auth_user.id', '=', 'users.id')
            ->where('users.company_id', $actor->company_id)
            ->orderBy('users.full_name')
            ->get(['users.id', 'users.full_name', 'users.is_active', 'auth_user.email']);

        $grants = DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->leftJoin('outlets', 'outlets.id', '=', 'user_roles.outlet_id')
            ->where('users.company_id', $actor->company_id)
            ->where('roles.company_id', $actor->company_id)
            ->orderBy('roles.name')
            ->get([
                'user_roles.user_id',
                'user_roles.id as user_role_id',
                'roles.id as role_id',
                'roles.code as role_code',
                'roles.name as role_name',
                'user_roles.outlet_id',
                'outlets.name as outlet_name',
            ])
            ->groupBy('user_id');

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'fullName' => $row->full_name,
            'email' => $row->email,
            'isActive' => (bool) $row->is_active,
            'grants' => ($grants[$row->id] ?? collect())->map(fn ($grant) => [
                'userRoleId' => $grant->user_role_id,
                'roleId' => $grant->role_id,
                'roleCode' => $grant->role_code,
                'roleName' => $grant->role_name,
                'outletId' => $grant->outlet_id,
                'outletName' => $grant->outlet_name,
            ])->values()->all(),
        ])->all();
    }

    /** @return list<array{id: string, code: string, name: string, permissions: list<string>}> */
    public function listCompanyRoles(User $actor): array
    {
        if (! $actor->can('user.manage') && ! $actor->can('role.manage')) {
            throw AccessException::forbidden();
        }

        return Role::query()
            ->where('company_id', $actor->company_id)
            ->orderBy('created_at')
            ->with('permissions:code')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('code')->all(),
            ])
            ->all();
    }

    /** @return list<array{code: string, description: ?string}> */
    public function listPermissions(): array
    {
        return Permission::query()->orderBy('code')->get(['code', 'description'])->toArray();
    }

    /** @return list<array{id: string, code: string, name: string}> */
    public function listCompanyOutlets(User $actor): array
    {
        return Outlet::query()
            ->where('company_id', $actor->company_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function listAuditLogs(User $actor, int $limit = 100): array
    {
        $this->assertPermission($actor, 'audit.view');

        return AuditLog::query()
            ->with('actor:id,full_name')
            ->where('company_id', $actor->company_id)
            ->orderByDesc('created_at')
            ->limit(min(max($limit, 1), 500))
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'createdAt' => $log->created_at->toIso8601String(),
                'action' => $log->action,
                'entityType' => $log->entity_type,
                'entityId' => $log->entity_id,
                'reason' => $log->reason,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
                'actorName' => $log->actor?->full_name,
            ])
            ->all();
    }

    // --- Mutations ---------------------------------------------------------------

    public function assignRole(User $actor, array $raw, AuditContext $context): void
    {
        $input = $this->parse($raw, [
            'userId' => self::UUID_RULE,
            'roleId' => self::UUID_RULE,
            'outletId' => ['present', 'nullable', 'uuid'],
        ]);
        $scope = AccessScope::of($input['outletId']);
        $this->assertPermission($actor, 'user.manage', $scope);
        $this->assertNotSelf($actor, $input['userId']);

        DB::transaction(function () use ($actor, $input, $scope, $context) {
            $target = $this->loadTargetUser($actor, $input['userId']);
            $role = $this->loadRole($actor, $input['roleId']);
            $outlet = $this->assertOutletInCompany($actor, $input['outletId']);
            $this->authorize($actor, 'dominate', [$target, $scope]);
            $this->authorize($actor, 'grant', [$role, $scope]);

            $inserted = DB::table('user_roles')->insertOrIgnore([
                'user_id' => $target->id,
                'role_id' => $role->id,
                'outlet_id' => $input['outletId'],
            ]);
            if ($inserted === 0) {
                throw new AccessException('conflict', 'User sudah memiliki role ini.');
            }

            $this->audit->record([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet?->brand_id,
                'outlet_id' => $input['outletId'],
                'actor_id' => $actor->id,
                'action' => 'rbac.role_assigned',
                'entity_type' => 'user',
                'entity_id' => $target->id,
                'after' => ['role' => $role->code, 'outletId' => $input['outletId']],
            ], $context);
        });
    }

    public function revokeRole(User $actor, array $raw, AuditContext $context): void
    {
        $input = $this->parse($raw, ['userRoleId' => self::UUID_RULE]);

        DB::transaction(function () use ($actor, $input, $context) {
            $assignment = DB::table('user_roles')
                ->join('users', 'users.id', '=', 'user_roles.user_id')
                ->where('user_roles.id', $input['userRoleId'])
                ->where('users.company_id', $actor->company_id)
                ->first(['user_roles.id', 'user_roles.user_id', 'user_roles.role_id', 'user_roles.outlet_id']);
            if ($assignment === null) {
                throw AccessException::notFound();
            }

            $scope = AccessScope::of($assignment->outlet_id);
            $this->assertPermission($actor, 'user.manage', $scope);
            $this->assertNotSelf($actor, $assignment->user_id);

            $target = $this->loadTargetUser($actor, $assignment->user_id);
            $role = $this->loadRole($actor, $assignment->role_id);
            $this->authorize($actor, 'dominate', [$target, $scope]);
            $this->authorize($actor, 'grant', [$role, $scope]);

            if ($role->code === Rbac::SUPER_ADMIN_ROLE && $assignment->outlet_id === null) {
                // Cegah company kehilangan Super Admin aktif terakhir.
                $remaining = DB::table('user_roles')
                    ->join('users', 'users.id', '=', 'user_roles.user_id')
                    ->where('user_roles.role_id', $role->id)
                    ->whereNull('user_roles.outlet_id')
                    ->where('users.is_active', true)
                    ->where('user_roles.id', '!=', $assignment->id)
                    ->count();
                if ($remaining < 1) {
                    throw new AccessException('conflict', 'Tidak bisa mencabut Super Admin terakhir.');
                }
            }

            DB::table('user_roles')->where('id', $assignment->id)->delete();
            $this->audit->record([
                'company_id' => $actor->company_id,
                'outlet_id' => $assignment->outlet_id,
                'actor_id' => $actor->id,
                'action' => 'rbac.role_revoked',
                'entity_type' => 'user',
                'entity_id' => $target->id,
                'before' => ['role' => $role->code, 'outletId' => $assignment->outlet_id],
            ], $context);
        });
    }

    /**
     * Status di tabel users adalah sumber kebenaran (dicek di setiap request).
     * Ban di Supabase Auth adalah lapisan tambahan agar token tidak bisa
     * di-refresh; kegagalannya dilaporkan sebagai peringatan.
     *
     * @return array{warning?: string}
     */
    public function setUserActive(User $actor, array $raw, AuditContext $context, AuthAdmin $authAdmin): array
    {
        $input = $this->parse($raw, [
            'userId' => self::UUID_RULE,
            'isActive' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $isActive = filter_var($input['isActive'], FILTER_VALIDATE_BOOLEAN);
        $scope = AccessScope::company();
        $this->assertPermission($actor, 'user.manage', $scope);
        $this->assertNotSelf($actor, $input['userId']);

        $changed = DB::transaction(function () use ($actor, $input, $isActive, $scope, $context) {
            $target = $this->loadTargetUser($actor, $input['userId']);
            $this->authorize($actor, 'dominate', [$target, $scope]);
            if ($target->is_active === $isActive) {
                return false;
            }

            DB::table('users')->where('id', $target->id)->update(['is_active' => $isActive, 'updated_at' => DB::raw('now()')]);
            $this->audit->record([
                'company_id' => $actor->company_id,
                'actor_id' => $actor->id,
                'action' => 'user.status_changed',
                'entity_type' => 'user',
                'entity_id' => $target->id,
                'before' => ['isActive' => $target->is_active],
                'after' => ['isActive' => $isActive],
                'reason' => $input['reason'] ?? null,
            ], $context);

            return true;
        });
        if (! $changed) {
            return [];
        }

        try {
            $authAdmin->setBanned($input['userId'], ! $isActive);

            return [];
        } catch (SupabaseAuthException) {
            return ['warning' => 'Status tersimpan, tetapi sinkronisasi ke Supabase Auth gagal. Coba ulangi nanti.'];
        }
    }

    public function setRolePermission(User $actor, array $raw, AuditContext $context): void
    {
        $input = $this->parse($raw, [
            'roleId' => self::UUID_RULE,
            'permissionCode' => ['required', 'string', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            'granted' => ['required', 'boolean'],
        ], ['permissionCode.regex' => 'Kode permission tidak valid.']);
        $granted = filter_var($input['granted'], FILTER_VALIDATE_BOOLEAN);
        $this->assertPermission($actor, 'role.manage', AccessScope::company());

        DB::transaction(function () use ($actor, $input, $granted, $context) {
            $role = $this->loadRole($actor, $input['roleId']);
            $this->authorize($actor, 'updatePermission', [$role, $input['permissionCode']]);
            if (! Permission::query()->whereKey($input['permissionCode'])->exists()) {
                throw AccessException::notFound();
            }

            $row = ['role_id' => $role->id, 'permission_code' => $input['permissionCode']];
            $changed = $granted
                ? DB::table('role_permissions')->insertOrIgnore($row)
                : DB::table('role_permissions')->where($row)->delete();
            if ($changed === 0) {
                return;
            }

            $this->audit->record([
                'company_id' => $actor->company_id,
                'actor_id' => $actor->id,
                'action' => $granted ? 'rbac.permission_granted' : 'rbac.permission_revoked',
                'entity_type' => 'role',
                'entity_id' => $role->id,
                'after' => ['role' => $role->code, 'permission' => $input['permissionCode']],
            ], $context);
        });
    }

    /**
     * Mendaftarkan staf baru. Jika email belum ada di Supabase Auth, dibuat
     * undangan (user memilih password sendiri lewat link). Jika sudah ada
     * (mis. dibuat via dashboard Supabase), akun tsb langsung dihubungkan.
     *
     * @return array{status: 'invited', userId: string, hashedToken: string}|array{status: 'linked', userId: string}
     */
    public function inviteStaff(User $actor, array $raw, AuditContext $context, AuthAdmin $authAdmin): array
    {
        $raw['email'] = is_string($raw['email'] ?? null) ? strtolower(trim($raw['email'])) : ($raw['email'] ?? null);
        $raw['fullName'] = is_string($raw['fullName'] ?? null) ? trim($raw['fullName']) : ($raw['fullName'] ?? null);
        $input = $this->parse($raw, [
            'email' => ['required', 'string', 'email:rfc'],
            'fullName' => ['required', 'string', 'min:2', 'max:100'],
            'roleId' => self::UUID_RULE,
            'outletId' => ['present', 'nullable', 'uuid'],
        ], [
            'email.required' => 'Format email tidak valid.',
            'email.email' => 'Format email tidak valid.',
            'fullName.required' => 'Nama minimal 2 karakter.',
            'fullName.min' => 'Nama minimal 2 karakter.',
        ]);
        $scope = AccessScope::of($input['outletId']);
        $this->assertPermission($actor, 'user.manage', $scope);

        // Validasi role & outlet sebelum menyentuh Supabase Auth.
        $role = $this->loadRole($actor, $input['roleId']);
        $outlet = $this->assertOutletInCompany($actor, $input['outletId']);
        $this->authorize($actor, 'grant', [$role, $scope]);

        $existingAuthId = DB::table('auth.users')->whereRaw('lower(email) = ?', [$input['email']])->value('id');
        if ($existingAuthId !== null && DB::table('users')->where('id', $existingAuthId)->exists()) {
            throw new AccessException('conflict', 'Email ini sudah terdaftar sebagai staf.');
        }

        $invite = $existingAuthId === null ? $authAdmin->createInvite($input['email'], $input['fullName']) : null;
        $userId = $existingAuthId ?? $invite['userId'];

        DB::transaction(function () use ($actor, $input, $role, $outlet, $invite, $userId, $context) {
            DB::table('users')->insert([
                'id' => $userId,
                'company_id' => $actor->company_id,
                'full_name' => $input['fullName'],
            ]);
            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $role->id,
                'outlet_id' => $input['outletId'],
            ]);
            $this->audit->record([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet?->brand_id,
                'outlet_id' => $input['outletId'],
                'actor_id' => $actor->id,
                'action' => $invite !== null ? 'user.invited' : 'user.linked',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'after' => ['email' => $input['email'], 'role' => $role->code, 'outletId' => $input['outletId']],
            ], $context);
        });

        return $invite !== null
            ? ['status' => 'invited', 'userId' => $userId, 'hashedToken' => $invite['hashedToken']]
            : ['status' => 'linked', 'userId' => $userId];
    }

    /** Membuat link reset password sekali pakai. Token tidak pernah masuk audit log. */
    public function createRecoveryLink(User $actor, array $raw, AuditContext $context, AuthAdmin $authAdmin): string
    {
        $input = $this->parse($raw, ['userId' => self::UUID_RULE]);
        $scope = AccessScope::company();
        $this->assertPermission($actor, 'user.manage', $scope);
        $this->assertNotSelf($actor, $input['userId']);

        $target = $this->loadTargetUser($actor, $input['userId']);
        $this->authorize($actor, 'dominate', [$target, $scope]);
        if (! $target->is_active) {
            throw new AccessException('conflict', 'Aktifkan akun ini dulu sebelum membuat link reset password.');
        }
        $email = $this->access->currentUser($target->id)?->email;
        if (! is_string($email)) {
            throw AccessException::notFound();
        }

        $hashedToken = $authAdmin->createRecovery($email);
        $this->audit->record([
            'company_id' => $actor->company_id,
            'actor_id' => $actor->id,
            'action' => 'user.recovery_link_created',
            'entity_type' => 'user',
            'entity_id' => $target->id,
        ], $context);

        return $hashedToken;
    }

    // --- Pemeriksaan -------------------------------------------------------------

    private function assertPermission(User $actor, string $permission, ?AccessScope $scope = null): void
    {
        if (! $actor->can($permission, $scope)) {
            throw AccessException::forbidden();
        }
    }

    private function assertNotSelf(User $actor, string $userId): void
    {
        if (strcasecmp($actor->id, $userId) === 0) {
            throw AccessException::forbidden('Anda tidak bisa mengubah akses akun sendiri.');
        }
    }

    /** Menjalankan Policy dan meneruskan alasannya sebagai pesan untuk user. */
    private function authorize(User $actor, string $ability, array $arguments): void
    {
        $response = Gate::forUser($actor)->inspect($ability, $arguments);
        if ($response->denied()) {
            throw AccessException::forbidden($response->message() ?? 'Anda tidak memiliki izin untuk tindakan ini.');
        }
    }

    /** Scope company: user company lain diperlakukan sebagai tidak ada. */
    private function loadTargetUser(User $actor, string $userId): User
    {
        return User::query()->where('company_id', $actor->company_id)->find($userId)
            ?? throw AccessException::notFound();
    }

    private function loadRole(User $actor, string $roleId): Role
    {
        return Role::query()->where('company_id', $actor->company_id)->find($roleId)
            ?? throw AccessException::notFound();
    }

    private function assertOutletInCompany(User $actor, ?string $outletId): ?Outlet
    {
        if ($outletId === null) {
            return null;
        }

        return Outlet::query()->where('company_id', $actor->company_id)->find($outletId)
            ?? throw AccessException::notFound();
    }

    /** @return array<string, mixed> */
    private function parse(array $input, array $rules, array $messages = []): array
    {
        $validator = Validator::make($input, $rules, $messages);
        if ($validator->fails()) {
            $message = $validator->errors()->first();
            $custom = in_array($message, $messages, true);

            throw new AccessException('invalid', $custom ? $message : 'Input tidak valid.');
        }

        return array_intersect_key($input, $rules);
    }
}
