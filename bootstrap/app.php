<?php

use App\Exceptions\AccessException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifySupabaseSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [VerifySupabaseSession::class, HandleInertiaRequests::class]);
        // `next` hanya untuk halaman: alamat POST tidak bisa dibuka lagi setelah login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->isMethodSafe()
            ? '/login?'.http_build_query(['next' => $request->getRequestUri()])
            : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReport(AccessException::class);

        // Pesan QueryException memuat nilai parameter, yang bisa berisi data pribadi.
        $exceptions->report(function (QueryException $error) {
            Log::error('query_failed', [
                'sql' => $error->getSql(),
                'code' => $error->getCode(),
                'connection' => $error->getConnectionName(),
            ]);
        })->stop();

        $exceptions->render(function (AccessException $error, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $error->getMessage()], match ($error->reason) {
                    'unauthenticated' => 401,
                    'forbidden' => 403,
                    'not_found' => 404,
                    'conflict' => 409,
                    default => 422,
                });
            }
            Inertia::flash('error', $error->getMessage());

            return $error->reason === 'unauthenticated' ? redirect('/login') : back();
        });

        $exceptions->respond(function (Response $response, Throwable $error, Request $request) {
            $status = $response->getStatusCode();
            if ($request->is('api/*')) {
                return $response;
            }
            if ($status === 419 && $request->header('X-Inertia')) {
                Inertia::flash('error', 'Halaman kedaluwarsa. Coba lagi.');

                return back();
            }
            // 500 dan 503 memakai view Blade statis: halaman Inertia butuh data akun
            // dari database, yang justru bisa menjadi penyebab error.
            $page = ['403' => 'errors/forbidden', '404' => 'errors/not-found'][$status] ?? null;

            return $page === null ? $response : Inertia::render($page)->toResponse($request)->setStatusCode($status);
        });
    })->create();
