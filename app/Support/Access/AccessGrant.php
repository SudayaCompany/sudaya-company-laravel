<?php

namespace App\Support\Access;

/** Satu assignment role milik user. `outletId` null berarti berlaku di semua outlet company. */
final readonly class AccessGrant
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $userRoleId,
        public string $roleId,
        public string $roleCode,
        public string $roleName,
        public ?string $outletId,
        public array $permissions,
    ) {}
}
