<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Attendance\AttendancePhotoStorage;
use App\Services\Attendance\SupabaseAttendancePhotoStorage;
use App\Services\Auth\AuthAdmin;
use App\Services\Auth\StaffUserProvider;
use App\Services\Auth\SupabaseAuthAdmin;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\SupabaseMenuImageStorage;
use App\Support\Access\AccessScope;
use App\Support\Access\Rbac;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public array $bindings = [
        AuthAdmin::class => SupabaseAuthAdmin::class,
        MenuImageStorage::class => SupabaseMenuImageStorage::class,
        AttendancePhotoStorage::class => SupabaseAttendancePhotoStorage::class,
    ];

    public function boot(): void
    {
        Auth::provider('staff', fn ($app, array $config) => new StaffUserProvider($app['hash'], $config['model']));

        foreach (Rbac::codes() as $permission) {
            Gate::define($permission, fn (User $user, ?AccessScope $scope = null) => $user->is_active
                && $user->hasPermission($permission, $scope));
        }
    }
}
