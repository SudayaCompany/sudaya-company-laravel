<?php

namespace App\Http\Middleware;

use App\Support\Access\RouteAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** Menu yang sudah dipindahkan ke Laravel. Tambah saat modulnya selesai. */
    private const NAVIGATION = ['/admin', '/admin/products', '/admin/categories', '/admin/modifiers', '/admin/users', '/admin/roles', '/admin/audit'];

    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => $this->auth($request),
        ];
    }

    /** Token Supabase tidak pernah ikut ke sini. */
    private function auth(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null || ! $user->isStaffActive()) {
            return null;
        }

        return [
            'user' => [
                'id' => $user->id,
                'fullName' => $user->full_name,
                'email' => $user->email,
                'company' => $user->company->name,
                'roles' => $user->roleNames(),
            ],
            'navigation' => array_values(array_filter(
                self::NAVIGATION,
                fn (string $path) => RouteAccess::canAccess($user->grants(), $path),
            )),
        ];
    }
}
