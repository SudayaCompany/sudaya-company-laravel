<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Department pemesan (keputusan owner 12 November 2026): setiap pesanan pelanggan mencantumkan
 * department/unit kerja (mis. Fundraising, Pendidikan & Pendayagunaan) untuk pengantaran dan
 * laporan. Aditif saja: kolom baru boleh kosong (pesanan lama dan pesanan kasir).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table public.orders add column if not exists customer_department text');
    }

    public function down(): void
    {
        DB::statement('alter table public.orders drop column if exists customer_department');
    }
};
