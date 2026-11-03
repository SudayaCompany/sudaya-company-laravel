<?php

namespace App\Support\Access;

/**
 * Scope pengecekan permission:
 * - anyOutlet(): di outlet mana pun
 * - company(): harus berlaku company-wide
 * - outlet($id): berlaku di outlet tsb (grant company-wide ikut)
 */
final readonly class AccessScope
{
    private function __construct(private bool $any, private ?string $outletId) {}

    public static function anyOutlet(): self
    {
        return new self(true, null);
    }

    public static function company(): self
    {
        return new self(false, null);
    }

    public static function outlet(string $outletId): self
    {
        return new self(false, $outletId);
    }

    /** Dari nilai `outlet_id` assignment: null = company-wide. */
    public static function of(?string $outletId): self
    {
        return new self(false, $outletId);
    }

    public function covers(AccessGrant $grant): bool
    {
        if ($this->any) {
            return true;
        }
        if ($this->outletId === null) {
            return $grant->outletId === null;
        }

        return $grant->outletId === null || $grant->outletId === $this->outletId;
    }
}
