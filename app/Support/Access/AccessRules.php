<?php

namespace App\Support\Access;

/** Aturan otorisasi murni (tanpa I/O), dipakai Gate, Policy, service, dan redirect login. */
final class AccessRules
{
    /**
     * @param  list<AccessGrant>  $grants
     * @return array<string, true>
     */
    public static function permissionSet(array $grants, ?AccessScope $scope = null): array
    {
        $scope ??= AccessScope::anyOutlet();
        $held = [];
        foreach ($grants as $grant) {
            if (! $scope->covers($grant)) {
                continue;
            }
            foreach ($grant->permissions as $permission) {
                $held[$permission] = true;
            }
        }

        return $held;
    }

    /** @param list<AccessGrant> $grants */
    public static function hasPermission(array $grants, string $permission, ?AccessScope $scope = null): bool
    {
        return isset(self::permissionSet($grants, $scope)[$permission]);
    }

    /**
     * @param  list<AccessGrant>  $grants
     * @param  list<string>  $permissions
     */
    public static function hasAnyPermission(array $grants, array $permissions, ?AccessScope $scope = null): bool
    {
        $held = self::permissionSet($grants, $scope);
        foreach ($permissions as $permission) {
            if (isset($held[$permission])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True jika actor memegang semua `required` pada scope tsb.
     *
     * @param  list<AccessGrant>  $actor
     * @param  iterable<string>  $required
     */
    public static function coversPermissions(array $actor, iterable $required, AccessScope $scope): bool
    {
        $held = self::permissionSet($actor, $scope);
        foreach ($required as $permission) {
            if (! isset($held[$permission])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Anti privilege escalation: actor hanya boleh mengelola akun yang seluruh
     * permission-nya juga dimiliki actor pada scope operasi tsb.
     *
     * @param  list<AccessGrant>  $actor
     * @param  list<AccessGrant>  $target
     */
    public static function dominates(array $actor, array $target, ?AccessScope $scope = null): bool
    {
        return self::coversPermissions(
            $actor,
            array_keys(self::permissionSet($target)),
            $scope ?? AccessScope::company(),
        );
    }
}
