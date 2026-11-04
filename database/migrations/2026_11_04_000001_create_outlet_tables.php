<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration Laravel pertama. Additive saja (LARAVEL_DATABASE_MAPPING.md):
 * - tabel `migrations` Laravel dibuat otomatis di schema public, jadi RLS-nya
 *   diaktifkan agar tidak terbaca lewat REST Supabase;
 * - daftar meja resmi per outlet untuk validasi QR meja (keputusan EXT-026).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table public.migrations enable row level security');

        DB::statement(<<<'SQL'
            create table public.outlet_tables (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references public.companies(id),
                outlet_id uuid not null references public.outlets(id) on delete cascade,
                number text not null check (number ~ '^[A-Z0-9-]{1,10}$'),
                is_active boolean not null default true,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint outlet_tables_outlet_number_unique unique (outlet_id, number)
            )
            SQL);
        // Sama dengan tabel bisnis lain: RLS aktif tanpa policy, hanya server yang mengakses.
        DB::statement('alter table public.outlet_tables enable row level security');
    }

    public function down(): void
    {
        DB::statement('drop table if exists public.outlet_tables');
    }
};
