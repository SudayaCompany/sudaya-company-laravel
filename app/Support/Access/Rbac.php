<?php

namespace App\Support\Access;

/** Katalog RBAC (PRD §5, §80). Sumber tunggal untuk seeder, cek permission, dan test. */
final class Rbac
{
    public const PERMISSIONS = [
        'dashboard.view' => 'Melihat dashboard admin',

        'employee.view' => 'Melihat data karyawan',
        'employee.create' => 'Menambah karyawan',
        'employee.edit' => 'Mengubah data karyawan',

        'attendance.view' => 'Melihat absensi',
        'attendance.checkin' => 'Absen masuk',
        'attendance.checkout' => 'Absen pulang',
        'attendance.approve_outside_duty' => 'Menyetujui tugas luar',

        'product.view' => 'Melihat pengelolaan menu',
        'product.create' => 'Menambah menu',
        'product.edit' => 'Mengubah menu, harga & ketersediaan',
        'product.delete' => 'Menghapus menu',

        'inventory.view' => 'Melihat stok',
        'inventory.receive' => 'Mencatat stok masuk',
        'inventory.issue' => 'Mencatat stok keluar & waste',
        'inventory.adjust' => 'Mengajukan penyesuaian stok',
        'inventory.opname' => 'Melakukan stock opname',

        'order.view' => 'Melihat order',
        'order.create' => 'Membuat order (POS)',
        'order.update_status' => 'Mengubah status produksi order',
        'order.void' => 'Mengajukan void order',
        'order.refund' => 'Mengajukan refund order',

        'payment.view' => 'Melihat pembayaran',
        'payment.confirm_cash' => 'Konfirmasi pembayaran cash',
        'payment.refund' => 'Memproses refund pembayaran',

        'kds.view' => 'Membuka layar barista (KDS)',
        'kds.manage' => 'Mengubah urutan antrean (override FIFO)',

        'customer.view' => 'Melihat data customer',

        'cash.view' => 'Melihat sesi kas',
        'cash.open' => 'Membuka sesi kas (opening)',
        'cash.close' => 'Menutup sesi kas (closing)',

        'report.sales' => 'Laporan penjualan',
        'report.inventory' => 'Laporan inventory',
        'report.attendance' => 'Laporan absensi',

        'approval.view' => 'Melihat daftar approval',
        'approval.approve' => 'Menyetujui approval',
        'approval.reject' => 'Menolak approval',

        'audit.view' => 'Melihat audit log',

        'settings.manage' => 'Mengubah konfigurasi outlet & sistem',
        'user.manage' => 'Mengelola akun & assignment role',
        'role.manage' => 'Mengelola permission tiap role',
    ];

    public const ROLES = [
        'super_admin' => 'Super Admin',
        'owner' => 'Owner',
        'manager' => 'Manager',
        'admin' => 'Admin',
        'barista' => 'Barista',
        'cashier' => 'Kasir',
    ];

    /** Role yang permission-nya selalu = semua permission dan tidak bisa diedit. */
    public const SUPER_ADMIN_ROLE = 'super_admin';

    /** Permission default saat role pertama kali dibuat (least privilege). */
    public const DEFAULT_ROLE_PERMISSIONS = [
        // Akses bisnis & laporan; tanpa administrasi sistem dan operasi kasir harian.
        'owner' => [
            'dashboard.view',
            'employee.view',
            'employee.create',
            'employee.edit',
            'attendance.view',
            'attendance.approve_outside_duty',
            'product.view',
            'product.create',
            'product.edit',
            'product.delete',
            'inventory.view',
            'order.view',
            'payment.view',
            'kds.view',
            'customer.view',
            'cash.view',
            'report.sales',
            'report.inventory',
            'report.attendance',
            'approval.view',
            'approval.approve',
            'approval.reject',
            'audit.view',
        ],
        // Operasional outlet penuh + approval.
        'manager' => [
            'dashboard.view',
            'employee.view',
            'employee.create',
            'employee.edit',
            'attendance.view',
            'attendance.checkin',
            'attendance.checkout',
            'attendance.approve_outside_duty',
            'product.view',
            'product.edit',
            'inventory.view',
            'inventory.receive',
            'inventory.issue',
            'inventory.adjust',
            'inventory.opname',
            'order.view',
            'order.create',
            'order.update_status',
            'order.void',
            'order.refund',
            'payment.view',
            'payment.confirm_cash',
            'payment.refund',
            'kds.view',
            'kds.manage',
            'customer.view',
            'cash.view',
            'cash.open',
            'cash.close',
            'report.sales',
            'report.inventory',
            'report.attendance',
            'approval.view',
            'approval.approve',
            'approval.reject',
        ],
        // Pengelola data: menu, stok, customer (PRD §5.3).
        'admin' => [
            'dashboard.view',
            'product.view',
            'product.create',
            'product.edit',
            'product.delete',
            'inventory.view',
            'inventory.receive',
            'inventory.issue',
            'inventory.adjust',
            'inventory.opname',
            'customer.view',
            'report.inventory',
        ],
        // Absen + semua urusan pesanan (keputusan owner 11 November 2026): termasuk menerima uang
        // bayar-saat-diantar di meja. Stok & pengeluaran hanya Admin/Manager. Void pesanan lunas
        // hanya pengajuan (eksekusi lewat approval Manager); override antrean tidak boleh.
        'barista' => [
            'attendance.checkin',
            'attendance.checkout',
            'order.view',
            'order.create',
            'order.update_status',
            'order.void',
            'payment.view',
            'payment.confirm_cash',
            'kds.view',
        ],
        // POS, pembayaran cash, sesi kas.
        'cashier' => [
            'attendance.checkin',
            'attendance.checkout',
            'order.view',
            'order.create',
            'order.refund',
            'payment.view',
            'payment.confirm_cash',
            'customer.view',
            'cash.view',
            'cash.open',
            'cash.close',
        ],
    ];

    /** Permission yang menandai seseorang pengguna aplikasi Staff. */
    public const STAFF_APP_PERMISSIONS = ['attendance.checkin', 'order.create', 'kds.view'];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
