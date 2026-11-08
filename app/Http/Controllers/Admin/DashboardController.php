<?php

namespace App\Http\Controllers\Admin;

use App\Services\Dashboard\DashboardSummary;
use App\Services\Orders\OrderEngine;
use App\Support\Access\AccessGrant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __invoke(Request $request, OrderEngine $orders, DashboardSummary $summary): Response
    {
        $user = $request->user();
        // Outlet yang boleh dilihat di dasbor; ?outlet= memilih salah satunya.
        $outlets = $orders->outletsWithPermission($user, 'dashboard.view');
        $requested = $request->query('outlet');
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $requested))[0] ?? $outlets[0] ?? null;

        return Inertia::render('admin/dashboard', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name'], 'brandName' => $o['brandName'], 'code' => $o['code']], $outlets),
            'outlet' => $outlet === null ? null : ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'grants' => array_map(fn (AccessGrant $grant) => [
                'userRoleId' => $grant->userRoleId,
                'roleName' => $grant->roleName,
                'outletId' => $grant->outletId,
            ], $user->grants()),
            // Angka dimuat setelah halaman tampil (skeleton), agar dasbor terasa cepat.
            'summary' => Inertia::defer(fn () => $outlet === null ? null : $summary->forOutlet($user, $outlet)),
        ]);
    }
}
