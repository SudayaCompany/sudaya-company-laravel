<?php

namespace App\Http\Controllers\Admin;

use App\Services\Auth\AccessLoader;
use App\Support\Access\AccessGrant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __invoke(Request $request, AccessLoader $access): Response
    {
        $user = $request->user();

        return Inertia::render('admin/dashboard', [
            'outlets' => $access->accessibleOutlets($user),
            'grants' => array_map(fn (AccessGrant $grant) => [
                'userRoleId' => $grant->userRoleId,
                'roleName' => $grant->roleName,
                'outletId' => $grant->outletId,
            ], $user->grants()),
        ]);
    }
}
