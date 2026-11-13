<?php

use App\Http\Controllers\Admin\AdminCashController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\ModifierController;
use App\Http\Controllers\Admin\OutletController;
use App\Http\Controllers\Admin\OutsideDutyController as AdminOutsideDutyController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchasingController;
use App\Http\Controllers\Admin\RecipeController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SiteContentController;
use App\Http\Controllers\Admin\StockOpnameController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AppLinksController;
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
use App\Http\Controllers\SeoController;
use App\Http\Controllers\Staff\AttendanceController as StaffAttendanceController;
use App\Http\Controllers\Staff\OutsideDutyController as StaffOutsideDutyController;
use App\Http\Controllers\StaffController;
use App\Http\Middleware\AuthorizeRoute;
use App\Http\Middleware\EnsureActiveStaff;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

// Mesin pencari: mengikuti domain aplikasi, hanya halaman publik.
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);

// APK Android (Trusted Web Activity) aplikasi staf; 404 sampai paket & sidik jari diisi di env.
Route::get('/.well-known/assetlinks.json', AppLinksController::class);

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
        // Absen GPS + selfie (M7-lite). Izin dicek ulang per outlet di AttendanceEngine.
        Route::get('/staff/attendance', [StaffAttendanceController::class, 'index']);
        Route::get('/barista', [StaffAttendanceController::class, 'index']);
        Route::post('/staff/attendance/location', [StaffAttendanceController::class, 'location']);
        Route::post('/staff/attendance/face', [StaffAttendanceController::class, 'face']);
        Route::post('/staff/attendance/check-in', [StaffAttendanceController::class, 'checkIn']);
        Route::post('/staff/attendance/check-out', [StaffAttendanceController::class, 'checkOut']);
        Route::get('/staff/attendance/history', [StaffAttendanceController::class, 'history']);
        Route::post('/staff/attendance/corrections', [StaffAttendanceController::class, 'correction']);
        // Tugas luar (PRD §15): ajukan, pantau, batalkan yang belum diputuskan.
        Route::get('/staff/outside-duty', [StaffOutsideDutyController::class, 'index']);
        Route::post('/staff/outside-duty', [StaffOutsideDutyController::class, 'store']);
        Route::post('/staff/outside-duty/{duty}/cancel', [StaffOutsideDutyController::class, 'cancel']);
        Route::get('/pos', [PosController::class, 'index']);
        Route::post('/pos/orders', [PosController::class, 'store']);
        Route::get('/pos/orders', [PosOrderController::class, 'index']);
        Route::get('/pos/orders/{order}', [PosOrderController::class, 'show']);
        Route::get('/pos/orders/{order}/receipt', [PosOrderController::class, 'receipt']);
        Route::post('/pos/orders/{order}/pay', [OrderActionController::class, 'pay']);
        Route::post('/pos/orders/{order}/confirm-qris', [OrderActionController::class, 'confirmQris']);
        Route::post('/pos/orders/{order}/cancel', [OrderActionController::class, 'cancel']);
        Route::post('/pos/orders/{order}/refund', [OrderActionController::class, 'refund']);
        Route::post('/pos/approvals/{approval}/decide', [OrderActionController::class, 'decide']);
        Route::get('/pos/cash', [CashController::class, 'index']);
        Route::post('/pos/cash/open', [CashController::class, 'open']);
        Route::post('/pos/cash/movements', [CashController::class, 'movement']);
        Route::post('/pos/cash/close', [CashController::class, 'close']);
        Route::get('/kds', KdsController::class);
        // Alarm pesanan baru di semua halaman kasir (keputusan owner 12 November 2026).
        Route::get('/kds/alarm', [KdsController::class, 'alarm']);
        Route::post('/kds/orders/{order}/status', [OrderActionController::class, 'status']);

        Route::prefix('admin')->group(function () {
            Route::get('/', DashboardController::class);

            // Pemantauan untuk Owner/Manager: data dan izin sama dengan area kasir.
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
            Route::get('/orders/{order}/receipt', [AdminOrderController::class, 'receipt']);
            Route::get('/approvals', [ApprovalController::class, 'index']);
            // Satu pintu keputusan: pembatalan pesanan lunas atau koreksi absen.
            Route::post('/approvals/{approval}/decide', [ApprovalController::class, 'decide']);
            Route::get('/cash', [AdminCashController::class, 'index']);
            // Laporan penjualan + ekspor spreadsheet (report.sales).
            Route::get('/reports', [ReportController::class, 'sales']);
            Route::get('/reports/export', [ReportController::class, 'export']);
            // Data pelanggan & poin (hanya-baca, customer.view).
            Route::get('/customers', [CustomerController::class, 'index']);

            // Karyawan & absensi (M7-lite).
            Route::get('/attendance', [AttendanceController::class, 'index']);
            Route::get('/attendance/reports', [AttendanceController::class, 'reports']);
            Route::get('/attendance/reports/export', [AttendanceController::class, 'export']);
            Route::get('/attendance/settings', [AttendanceController::class, 'settings']);
            Route::post('/attendance/settings/outlets/{outlet}', [AttendanceController::class, 'updateGeofence']);
            Route::post('/attendance/settings/accuracy', [AttendanceController::class, 'updateAccuracy']);
            Route::get('/attendance/{attendance}/photo/{side}', [AttendanceController::class, 'photo']);
            Route::get('/attendance/face/{employee}', [AttendanceController::class, 'face']);
            Route::post('/attendance/face/{employee}/reset', [AttendanceController::class, 'resetFace']);
            Route::get('/outside-duty/{duty}/evidence', [AdminOutsideDutyController::class, 'evidence']);
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::get('/employees/create', [EmployeeController::class, 'create']);
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);

            // Stok (M5-lite). Izin rinci (adjust/receive/issue/opname) dicek di service.
            Route::get('/inventory', [InventoryController::class, 'index']);
            Route::get('/inventory/items/create', [InventoryController::class, 'create']);
            Route::post('/inventory/items', [InventoryController::class, 'store']);
            Route::get('/inventory/items/{item}/edit', [InventoryController::class, 'edit']);
            Route::put('/inventory/items/{item}', [InventoryController::class, 'update']);
            Route::get('/inventory/stock-in', [InventoryController::class, 'stockInPage']);
            Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
            Route::get('/inventory/stock-out', [InventoryController::class, 'stockOutPage']);
            Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);
            Route::get('/inventory/movements', [InventoryController::class, 'movements']);
            Route::get('/inventory/movements/{movement}/photo', [InventoryController::class, 'evidence']);
            Route::get('/inventory/opname', [StockOpnameController::class, 'index']);
            Route::post('/inventory/opname', [StockOpnameController::class, 'store']);
            Route::get('/inventory/opname/{opname}', [StockOpnameController::class, 'show']);
            Route::post('/inventory/opname/{opname}/counts', [StockOpnameController::class, 'saveCounts']);
            Route::post('/inventory/opname/{opname}/submit', [StockOpnameController::class, 'submit']);
            Route::post('/inventory/opname/{opname}/cancel', [StockOpnameController::class, 'cancel']);
            // Resep/BOM (Fase 2): bahan per porsi, dipotong otomatis saat pesanan dibayar.
            Route::get('/inventory/recipes', [RecipeController::class, 'index']);
            Route::get('/inventory/recipes/{product}', [RecipeController::class, 'edit']);
            Route::post('/inventory/recipes/{product}', [RecipeController::class, 'update']);

            // Supplier & purchase order (Fase 2): ajukan → setujui → terima barang → stok masuk.
            Route::get('/suppliers', [PurchasingController::class, 'suppliers']);
            Route::post('/suppliers', [PurchasingController::class, 'storeSupplier']);
            Route::put('/suppliers/{supplier}', [PurchasingController::class, 'updateSupplier']);
            Route::get('/purchase-orders', [PurchasingController::class, 'orders']);
            Route::get('/purchase-orders/create', [PurchasingController::class, 'create']);
            Route::post('/purchase-orders', [PurchasingController::class, 'store']);
            Route::get('/purchase-orders/{order}', [PurchasingController::class, 'show']);
            Route::post('/purchase-orders/{order}/receive', [PurchasingController::class, 'receive']);
            Route::post('/purchase-orders/{order}/cancel', [PurchasingController::class, 'cancel']);

            // Pengeluaran (Fase 2): catat + bukti → persetujuan Owner/Manager.
            Route::get('/expenses', [ExpenseController::class, 'index']);
            Route::post('/expenses', [ExpenseController::class, 'store']);
            Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel']);
            Route::get('/expenses/{expense}/evidence', [ExpenseController::class, 'evidence']);

            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users/invite', [UserController::class, 'invite']);
            Route::post('/users/assign-role', [UserController::class, 'assignRole']);
            Route::post('/users/revoke-role', [UserController::class, 'revokeRole']);
            Route::post('/users/set-active', [UserController::class, 'setActive']);
            Route::post('/users/recovery', [UserController::class, 'recovery']);

            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles/set-permission', [RoleController::class, 'setPermission']);

            Route::get('/audit', AuditController::class);

            // Konten halaman publik: tentang, program, pelatihan, sedekah, kontak (11 November 2026).
            Route::get('/site', [SiteContentController::class, 'edit']);
            Route::post('/site', [SiteContentController::class, 'update']);
            Route::get('/outlets', [OutletController::class, 'index']);
            Route::get('/outlets/{outlet}', [OutletController::class, 'edit']);
            Route::get('/outlets/{outlet}/qr', [OutletController::class, 'qr']);
            Route::put('/outlets/{outlet}', [OutletController::class, 'update']);
            Route::post('/outlets/{outlet}/ordering', [OutletController::class, 'updateOrdering']);
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
            Route::get('/products/export', [ProductController::class, 'export']);
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
