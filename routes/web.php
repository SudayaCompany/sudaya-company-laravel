<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ConfirmController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\AuthorizeRoute;
use App\Http\Middleware\EnsureActiveStaff;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'home');

// Halaman modul yang belum dipindahkan ke Laravel, agar tautan dan redirect
// setelah login tidak berakhir 404. Tidak ada fungsi bisnis di sini.
$pending = fn (string $module) => fn () => Inertia::render('pending', ['module' => $module]);

Route::get('/order', $pending('Sudaya Order'));
Route::get('/kiosk', $pending('Sudaya Kiosk'));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::post('/logout', LogoutController::class);
Route::get('/auth/confirm', ConfirmController::class);

Route::middleware(['auth', EnsureActiveStaff::class])->group(function () use ($pending) {
    Route::get('/auth/set-password', [SetPasswordController::class, 'show']);
    Route::post('/auth/set-password', [SetPasswordController::class, 'store']);

    Route::middleware(AuthorizeRoute::class)->group(function () use ($pending) {
        Route::get('/staff', StaffController::class);
        Route::get('/pos', $pending('POS'));
        Route::get('/kds', $pending('Barista Display'));

        Route::prefix('admin')->group(function () {
            Route::get('/', DashboardController::class);

            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users/invite', [UserController::class, 'invite']);
            Route::post('/users/assign-role', [UserController::class, 'assignRole']);
            Route::post('/users/revoke-role', [UserController::class, 'revokeRole']);
            Route::post('/users/set-active', [UserController::class, 'setActive']);
            Route::post('/users/recovery', [UserController::class, 'recovery']);

            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles/set-permission', [RoleController::class, 'setPermission']);

            Route::get('/audit', AuditController::class);
        });
    });
});
