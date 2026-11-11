<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bayar saat diantar (keputusan owner 11 November 2026): pesanan QR meja yang diantar dan dibayar
 * tunai langsung masuk antrean barista; barista menerima uang di meja lalu menandai lunas.
 * Aditif saja; kolom lama tidak diubah. Pesanan ini tidak pernah kedaluwarsa otomatis
 * (expires_at null, status sudah di antrean), sehingga fungsi expire_pending_orders tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table public.orders add column if not exists pay_on_delivery boolean not null default false');
    }

    public function down(): void
    {
        DB::statement('alter table public.orders drop column if exists pay_on_delivery');
    }
};
