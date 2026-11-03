<?php

namespace App\Http\Controllers;

use App\Services\Auth\AccessLoader;
use App\Support\Access\AccessRules;
use App\Support\Access\Rbac;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController
{
    public function __invoke(Request $request, AccessLoader $access): Response
    {
        $user = $request->user();

        return Inertia::render('staff/home', [
            'outlets' => $access->accessibleOutlets($user),
            'permissions' => array_keys(AccessRules::permissionSet($user->grants())),
            'staffPermissions' => Rbac::STAFF_APP_PERMISSIONS,
        ]);
    }
}
