<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Konten halaman publik per brand (permintaan owner 11 November 2026): tentang Sudaya, program
 * pelatihan & pemberdayaan petani kopi, info pelatihan, tautan sedekah Sahabat Berbagi, dan kontak
 * footer. Diubah Super Admin di Admin → Konten situs. Aditif saja.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table public.brands add column if not exists site_content jsonb not null default '{}'::jsonb");
    }

    public function down(): void
    {
        DB::statement('alter table public.brands drop column if exists site_content');
    }
};
