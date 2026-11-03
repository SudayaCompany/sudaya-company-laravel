<?php

namespace App\Http\Middleware;

use App\Exceptions\AccessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman login yang menampilkan alasannya, jadi tidak terjadi redirect loop. */
class EnsureActiveStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isStaffActive()) {
            return $next($request);
        }
        if ($request->isMethodSafe()) {
            return redirect('/login');
        }

        throw new AccessException('unauthenticated', 'Sesi berakhir. Silakan login ulang.');
    }
}
