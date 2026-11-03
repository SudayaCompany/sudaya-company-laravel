<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessGrant;
use App\Support\Access\AccessRules;
use App\Support\Access\AccessScope;
use App\Support\Access\Rbac;
use Illuminate\Auth\Access\Response;

class RolePolicy
{
    /** Actor hanya bisa memberi atau mencabut role yang seluruh permission-nya ia miliki. */
    public function grant(User $actor, Role $role, AccessScope $scope): Response
    {
        return AccessRules::coversPermissions($actor->grants(), $role->permissionCodes(), $scope)
            ? Response::allow()
            : Response::deny('Role ini memiliki permission yang tidak Anda miliki.');
    }

    public function updatePermission(User $actor, Role $role, string $permission): Response
    {
        if ($role->code === Rbac::SUPER_ADMIN_ROLE) {
            return Response::deny('Permission Super Admin tidak bisa diubah.');
        }
        if (array_any($actor->grants(), fn (AccessGrant $grant) => $grant->roleId === $role->id)) {
            return Response::deny('Anda tidak bisa mengubah permission role yang Anda pegang sendiri.');
        }
        if (! AccessRules::coversPermissions($actor->grants(), [$permission], AccessScope::company())) {
            return Response::deny('Anda hanya bisa mengatur permission yang Anda miliki sendiri.');
        }

        return Response::allow();
    }
}
