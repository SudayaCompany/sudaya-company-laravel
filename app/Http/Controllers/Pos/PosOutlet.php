<?php

namespace App\Http\Controllers\Pos;

use App\Services\Orders\OrderEngine;
use Illuminate\Http\Request;

/** Outlet aktif untuk halaman staf (?outlet=), hanya dari outlet yang diizinkan. */
final class PosOutlet
{
    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    public static function resolve(OrderEngine $orders, Request $request, string $permission): array
    {
        $outlets = $orders->outletsWithPermission($request->user(), $permission);
        abort_if($outlets === [], 403);
        $requested = $request->query('outlet');
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $requested))[0] ?? $outlets[0];

        return [$outlets, $outlet];
    }
}
