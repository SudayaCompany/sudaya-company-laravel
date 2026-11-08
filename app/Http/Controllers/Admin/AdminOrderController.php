<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Pos\PosOrderController;

/**
 * Daftar dan detail pesanan di area admin (mis. Owner yang tidak memakai kasir). Data dan
 * izinnya sama dengan halaman kasir (order.view); aksi kasir tidak ditampilkan di sini.
 */
class AdminOrderController extends PosOrderController
{
    protected const AREA = 'admin';
}
