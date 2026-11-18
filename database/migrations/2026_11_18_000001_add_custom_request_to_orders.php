<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan di luar menu (keputusan owner 17 November 2026: "kasir isi harga dulu"): request masuk
 * sebagai baris tanpa harga; kasir mengisi harganya (atau menandai tidak tersedia), baru pelanggan
 * bisa membayar. Aditif saja: kolom baru boleh kosong (pesanan lama menyimpan request di catatan).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table public.orders
            add column if not exists custom_request text,
            add column if not exists custom_request_status text,
            add column if not exists custom_request_price bigint,
            add column if not exists custom_request_priced_by uuid references public.users(id),
            add column if not exists custom_request_priced_at timestamp with time zone');
        DB::statement(<<<'SQL'
            do $$
            begin
                if not exists (select 1 from pg_constraint where conname = 'orders_custom_request_check') then
                    alter table public.orders add constraint orders_custom_request_check check (
                        (custom_request is null and custom_request_status is null and custom_request_price is null)
                        or (custom_request is not null and custom_request_status = 'pending' and custom_request_price is null)
                        or (custom_request is not null and custom_request_status = 'priced' and custom_request_price >= 0)
                        or (custom_request is not null and custom_request_status = 'declined' and custom_request_price = 0)
                    );
                end if;
            end
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table public.orders drop constraint if exists orders_custom_request_check');
        DB::statement('alter table public.orders
            drop column if exists custom_request_priced_at,
            drop column if exists custom_request_priced_by,
            drop column if exists custom_request_price,
            drop column if exists custom_request_status,
            drop column if exists custom_request');
    }
};
