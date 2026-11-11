<?php

namespace App\Support\Access;

/** Permission minimal untuk tiap area internal, plus validasi tujuan setelah login. */
final class RouteAccess
{
    /** Salah satu permission cukup. Prefix terpanjang menang. */
    public const MAP = [
        '/admin/users' => ['user.manage'],
        '/admin/roles' => ['role.manage'],
        '/admin/audit' => ['audit.view'],
        '/admin/products' => ['product.view'],
        '/admin/categories' => ['product.view'],
        '/admin/modifiers' => ['product.view'],
        '/admin/outlets' => ['settings.manage'],
        '/admin/site' => ['settings.manage'],
        '/admin/outlet-tables' => ['settings.manage'],
        '/admin/orders' => ['order.view'],
        // Keputusan (setujui/tolak) diperiksa ulang per outlet di OrderWorkflow.
        '/admin/approvals' => ['approval.view'],
        '/admin/cash' => ['cash.view'],
        '/admin/reports' => ['report.sales'],
        '/admin/customers' => ['customer.view'],
        '/admin/inventory/stock-in' => ['inventory.receive'],
        '/admin/inventory/stock-out' => ['inventory.issue'],
        '/admin/inventory/recipes' => ['inventory.view'],
        '/admin/inventory' => ['inventory.view'],
        '/admin/suppliers' => ['inventory.view'],
        '/admin/purchase-orders' => ['inventory.view'],
        // Pengeluaran: pengaju (pemegang kas / pencatat stok keluar) atau peninjau; dicek ulang di service.
        '/admin/expenses' => ['cash.view', 'inventory.issue', 'approval.view'],
        '/admin/employees' => ['employee.view'],
        '/admin/attendance/settings' => ['settings.manage'],
        '/admin/attendance/reports' => ['report.attendance'],
        '/admin/attendance' => ['attendance.view'],
        '/admin/outside-duty' => ['attendance.approve_outside_duty', 'attendance.view'],
        '/admin' => ['dashboard.view'],
        '/staff/attendance' => ['attendance.checkin'],
        '/staff/face-enrollment' => ['attendance.checkin'],
        '/staff/outside-duty' => ['attendance.checkin'],
        '/staff' => Rbac::STAFF_APP_PERMISSIONS,
        '/pos' => ['order.create'],
        '/kds' => ['kds.view'],
    ];

    private const MAX_NEXT_LENGTH = 512;

    /** @return list<string>|null null = path tidak dilindungi peta ini */
    public static function requiredFor(string $path): ?array
    {
        $routes = array_keys(self::MAP);
        usort($routes, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($routes as $route) {
            if ($path === $route || str_starts_with($path, $route.'/')) {
                return self::MAP[$route];
            }
        }

        return null;
    }

    /** @param list<AccessGrant> $grants */
    public static function canAccess(array $grants, string $path): bool
    {
        $required = self::requiredFor($path);

        return $required === null || AccessRules::hasAnyPermission($grants, $required);
    }

    /**
     * Halaman awal sesuai akses yang benar-benar dimiliki; null = tanpa akses.
     *
     * @param  list<AccessGrant>  $grants
     */
    public static function landing(array $grants): ?string
    {
        foreach (['/admin', '/staff', '/kds'] as $route) {
            if (AccessRules::hasAnyPermission($grants, self::MAP[$route])) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Validasi parameter `next` agar tidak bisa dipakai untuk open redirect.
     * Hanya path relatif di origin sendiri yang diterima.
     */
    public static function safeNext(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_NEXT_LENGTH) {
            return null;
        }
        if (! str_starts_with($raw, '/') || str_starts_with($raw, '//')) {
            return null;
        }
        // Browser menormalkan "\" dan karakter kontrol, jadi input seperti ini bisa pindah origin.
        if (preg_match('/[\\\\\x00-\x20\x7f]/', $raw) === 1) {
            return null;
        }

        $path = self::pathOf($raw);
        // Dot segment ditolak karena /admin/../login bisa menembus pemeriksaan berikutnya.
        if (preg_match('#(^|/)(\.|%2e){1,2}(/|$)#i', $path) === 1) {
            return null;
        }
        if ($path === '/login' || str_starts_with($path, '/auth/')) {
            return null;
        }

        return $raw;
    }

    /**
     * Tujuan setelah login: `next` jika aman & diizinkan, kalau tidak landing.
     *
     * @param  list<AccessGrant>  $grants
     */
    public static function postLogin(array $grants, mixed $next): ?string
    {
        $safe = self::safeNext($next);
        if ($safe !== null && self::canAccess($grants, self::pathOf($safe))) {
            return $safe;
        }

        return self::landing($grants);
    }

    private static function pathOf(string $target): string
    {
        return strtok($target, '?#') ?: '/';
    }
}
