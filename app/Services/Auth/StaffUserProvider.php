<?php

namespace App\Services\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/** Guard sesi Laravel memuat user lewat sini agar email dan status ban ikut terbaca. */
final class StaffUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return app(AccessLoader::class)->currentUser((string) $identifier);
    }
}
