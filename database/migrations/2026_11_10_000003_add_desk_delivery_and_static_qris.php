<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pesanan meja kantor (permintaan owner 10 November 2026): biaya antar ke meja per pesanan
 * (diatur admin per outlet, awal 0) dan QRIS statis milik outlet (gambar diunggah admin,
 * pembayaran dikonfirmasi manual kasir). Aditif saja; kolom lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table public.outlets add column if not exists delivery_fee bigint not null default 0');
        DB::statement('alter table public.outlets add constraint outlets_delivery_fee_range check (delivery_fee between 0 and 1000000)');
        DB::statement('alter table public.outlets add column if not exists qris_image_path text');
        DB::statement('alter table public.orders add column if not exists delivery_fee bigint not null default 0');
        DB::statement('alter table public.orders add constraint orders_delivery_fee_non_negative check (delivery_fee >= 0)');
    }

    public function down(): void
    {
        DB::statement('alter table public.orders drop constraint if exists orders_delivery_fee_non_negative');
        DB::statement('alter table public.orders drop column if exists delivery_fee');
        DB::statement('alter table public.outlets drop column if exists qris_image_path');
        DB::statement('alter table public.outlets drop constraint if exists outlets_delivery_fee_range');
        DB::statement('alter table public.outlets drop column if exists delivery_fee');
    }
};
