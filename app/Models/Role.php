<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'code', 'name'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_code');
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return $this->permissions()->pluck('permissions.code')->all();
    }
}
