<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Pos\CashController;

/** Ringkasan sesi kas di area admin (cash.view), hanya-baca: buka/tutup laci tetap di kasir. */
class AdminCashController extends CashController
{
    protected const AREA = 'admin';
}
