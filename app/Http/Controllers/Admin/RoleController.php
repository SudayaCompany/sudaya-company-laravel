<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Auth\AccessAdmin;
use App\Support\Access\AccessGrant;
use App\Support\Access\AccessRules;
use App\Support\Access\AccessScope;
use App\Support\Access\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController
{
    public function __construct(private AccessAdmin $admin) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $grants = $actor->grants();

        return Inertia::render('admin/roles', [
            'roles' => $this->admin->listCompanyRoles($actor),
            'permissions' => $this->admin->listPermissions(),
            // Hanya untuk menonaktifkan checkbox; server tetap memvalidasi ulang.
            'superAdminRole' => Rbac::SUPER_ADMIN_ROLE,
            'actorPermissions' => array_keys(AccessRules::permissionSet($grants, AccessScope::company())),
            'actorRoleIds' => array_values(array_unique(array_map(fn (AccessGrant $grant) => $grant->roleId, $grants))),
        ]);
    }

    public function setPermission(Request $request): RedirectResponse
    {
        $this->admin->setRolePermission($request->user(), $request->all(), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Permission diperbarui.');

        return back();
    }
}
