<?php

namespace App\Http\Middleware;

use App\Support\Access\RouteAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemeriksaan awal per area memakai peta RouteAccess. Pemeriksaan per outlet
 * tetap dilakukan di service, dekat ke data.
 */
class AuthorizeRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(RouteAccess::canAccess($request->user()->grants(), $request->getPathInfo()), 403);

        return $next($request);
    }
}
