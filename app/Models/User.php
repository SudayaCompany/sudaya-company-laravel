<?php

namespace App\Models;

use App\Services\Auth\AccessLoader;
use App\Support\Access\AccessGrant;
use App\Support\Access\AccessRules;
use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Akun staf di `public.users`. Id sama dengan `auth.users.id`, jadi Supabase Auth
 * yang menyimpan email dan password. `email` dan `banned_until` berasal dari join.
 */
class User extends Authenticatable
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'full_name', 'phone', 'is_active'];

    /** @var list<AccessGrant>|null */
    private ?array $loadedGrants = null;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'banned_until' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Aktif di aplikasi dan tidak di-ban di Supabase Auth. */
    public function isStaffActive(): bool
    {
        return $this->is_active && ($this->banned_until === null || $this->banned_until->isPast());
    }

    /** @return list<AccessGrant> */
    public function grants(): array
    {
        return $this->loadedGrants ??= app(AccessLoader::class)->grants($this->id, $this->company_id);
    }

    public function hasPermission(string $permission, ?AccessScope $scope = null): bool
    {
        return AccessRules::hasPermission($this->grants(), $permission, $scope);
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_values(array_unique(array_map(fn (AccessGrant $grant) => $grant->roleName, $this->grants())));
    }
}
