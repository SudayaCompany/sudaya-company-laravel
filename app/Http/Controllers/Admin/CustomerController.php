<?php

namespace App\Http\Controllers\Admin;

use App\Services\Auth\AccessLoader;
use App\Services\Customers\CustomerInsights;
use App\Support\Access\AccessScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Data pelanggan & poin (hanya-baca) dari pesanan lunas yang mencantumkan nomor HP. */
class CustomerController
{
    public function __construct(private CustomerInsights $customers, private AccessLoader $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $outlets = array_values(array_filter($this->access->accessibleOutlets($user), fn ($o) => $user->can('customer.view', AccessScope::outlet($o['id']))));
        abort_if($outlets === [], 403);
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $request->query('outlet')))[0] ?? $outlets[0];
        $search = is_string($request->query('q')) ? mb_substr($request->query('q'), 0, 60) : null;

        return Inertia::render('admin/customers/index', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'q' => $search,
            ...$this->customers->list($user, $outlet, $search),
        ]);
    }
}
