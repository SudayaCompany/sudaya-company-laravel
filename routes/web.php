<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ModifierController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ConfirmController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\PosOrderController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\AuthorizeRoute;
use App\Http\Middleware\EnsureActiveStaff;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'home');

// Halaman modul yang belum dipindahkan ke Laravel, agar tautan dan redirect
// setelah login tidak berakhir 404. Tidak ada fungsi bisnis di sini.
$pending = fn (string $module) => fn () => Inertia::render('pending', ['module' => $module]);

// Menu customer (M2). Checkout dibangun di M3; quote hanya menghitung harga.
Route::get('/order', [OrderController::class, 'show']);
Route::post('/order/quote', [OrderController::class, 'quote']);
Route::post('/order/checkout', CheckoutController::class);
Route::get('/order/status/{order}', OrderStatusController::class);
Route::get('/kiosk', [OrderController::class, 'kiosk']);

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::post('/logout', LogoutController::class);
Route::get('/auth/confirm', ConfirmController::class);

Route::middleware(['auth', EnsureActiveStaff::class])->group(function () use ($pending) {
    Route::get('/auth/set-password', [SetPasswordController::class, 'show']);
    Route::post('/auth/set-password', [SetPasswordController::class, 'store']);

    Route::middleware(AuthorizeRoute::class)->group(function () use ($pending) {
        Route::get('/staff', StaffController::class);
        Route::get('/pos', [PosController::class, 'index']);
        Route::post('/pos/orders', [PosController::class, 'store']);
        Route::get('/pos/orders', [PosOrderController::class, 'index']);
        Route::get('/pos/orders/{order}', [PosOrderController::class, 'show']);
        Route::get('/pos/orders/{order}/receipt', [PosOrderController::class, 'receipt']);
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

            Route::get('/categories', [CategoryController::class, 'index']);
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

            Route::get('/modifiers', [ModifierController::class, 'index']);
            Route::post('/modifiers', [ModifierController::class, 'store']);
            Route::put('/modifiers/{group}', [ModifierController::class, 'update']);
            Route::delete('/modifiers/{group}', [ModifierController::class, 'destroy']);

            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/create', [ProductController::class, 'create']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::get('/products/{product}/edit', [ProductController::class, 'edit']);
            // POST, bukan PUT: form multipart (foto) tidak bisa dikirim dengan PUT di PHP.
            Route::post('/products/{product}', [ProductController::class, 'update']);
            Route::patch('/products/{product}/active', [ProductController::class, 'setActive']);
            Route::patch('/products/{product}/availability', [ProductController::class, 'setAvailability']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        });
    });
});
