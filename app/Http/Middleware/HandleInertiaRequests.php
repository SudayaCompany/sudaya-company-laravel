<?php

namespace App\Http\Middleware;

use App\Support\Access\RouteAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** Menu yang sudah dipindahkan ke Laravel. Tambah saat modulnya selesai. */
    private const NAVIGATION = ['/admin', '/admin/orders', '/admin/approvals', '/admin/cash', '/admin/products', '/admin/categories', '/admin/modifiers', '/admin/outlets', '/admin/users', '/admin/roles', '/admin/audit', '/staff', '/pos', '/kds'];

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
            // Kunci publishable memang publik (dipakai browser untuk Realtime). Sinyal realtime
            // tidak berisi data; data selalu dimuat ulang lewat server yang memeriksa izin.
            'realtime' => fn () => config('services.supabase.url') && config('services.supabase.publishable_key') ? [
                'url' => rtrim((string) config('services.supabase.url'), '/'),
                'key' => (string) config('services.supabase.publishable_key'),
            ] : null,
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
            // Nama permission (di scope mana pun) untuk menampilkan menu; izin tetap dicek di server.
            'permissions' => array_values(array_unique(array_merge([], ...array_map(fn ($grant) => $grant->permissions, $user->grants())))),
        ];
    }
}
