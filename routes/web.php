<?php

use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ModifierController;
use App\Http\Controllers\Admin\OutletController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\ConfirmController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SetPasswordController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KdsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\Pos\CashController;
use App\Http\Controllers\Pos\OrderActionController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\PosOrderController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\AuthorizeRoute;
use App\Http\Middleware\EnsureActiveStaff;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

// Menu customer, checkout guest, status pesanan, dan kiosk.
Route::get('/order', [OrderController::class, 'show']);
Route::post('/order/quote', [OrderController::class, 'quote']);
Route::post('/order/checkout', CheckoutController::class);
Route::get('/order/status/{order}', OrderStatusController::class);
Route::get('/kiosk', [OrderController::class, 'kiosk']);

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store']);
Route::post('/logout', LogoutController::class);
Route::get('/auth/confirm', ConfirmController::class);

Route::middleware(['auth', EnsureActiveStaff::class])->group(function () {
    Route::get('/auth/set-password', [SetPasswordController::class, 'show']);
    Route::post('/auth/set-password', [SetPasswordController::class, 'store']);

    Route::middleware(AuthorizeRoute::class)->group(function () {
        Route::get('/staff', StaffController::class);
        Route::get('/pos', [PosController::class, 'index']);
        Route::post('/pos/orders', [PosController::class, 'store']);
        Route::get('/pos/orders', [PosOrderController::class, 'index']);
        Route::get('/pos/orders/{order}', [PosOrderController::class, 'show']);
        Route::get('/pos/orders/{order}/receipt', [PosOrderController::class, 'receipt']);
        Route::post('/pos/orders/{order}/pay', [OrderActionController::class, 'pay']);
        Route::post('/pos/orders/{order}/cancel', [OrderActionController::class, 'cancel']);
        Route::post('/pos/orders/{order}/refund', [OrderActionController::class, 'refund']);
        Route::post('/pos/approvals/{approval}/decide', [OrderActionController::class, 'decide']);
        Route::get('/pos/cash', [CashController::class, 'index']);
        Route::post('/pos/cash/open', [CashController::class, 'open']);
        Route::post('/pos/cash/movements', [CashController::class, 'movement']);
        Route::post('/pos/cash/close', [CashController::class, 'close']);
        Route::get('/kds', KdsController::class);
        Route::post('/kds/orders/{order}/status', [OrderActionController::class, 'status']);

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

            // Owner/Manager: pantau pesanan dan putuskan pembatalan, tanpa aksi kasir.
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
            Route::post('/orders/approvals/{approval}/decide', [AdminOrderController::class, 'decide']);

            Route::get('/outlets', [OutletController::class, 'index']);
            Route::get('/outlets/{outlet}', [OutletController::class, 'edit']);
            Route::put('/outlets/{outlet}', [OutletController::class, 'update']);
            Route::post('/outlets/{outlet}/tables', [OutletController::class, 'storeTables']);
            Route::patch('/outlet-tables/{table}', [OutletController::class, 'updateTable']);

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
