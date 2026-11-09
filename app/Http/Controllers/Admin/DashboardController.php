<?php

namespace App\Http\Controllers\Admin;

use App\Services\Auth\AccessLoader;
use App\Services\Dashboard\DashboardStats;
use App\Support\Access\AccessScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __invoke(Request $request, AccessLoader $access, DashboardStats $stats): Response
    {
        $user = $request->user();
        // Outlet yang boleh dilihat dasbornya; ?outlet= hanya dari daftar ini.
        $outlets = array_values(array_filter(
            $access->accessibleOutlets($user),
            fn (array $o) => $user->can('dashboard.view', AccessScope::outlet($o['id'])),
        ));
        $requested = $request->query('outlet');
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $requested))[0] ?? $outlets[0] ?? null;

        return Inertia::render('admin/dashboard', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name'], 'brandName' => $o['brandName'], 'code' => $o['code']], $outlets),
            'outlet' => $outlet === null ? null : ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'stats' => $outlet === null ? null : $stats->summary($user, $outlet),
        ]);
    }
}
