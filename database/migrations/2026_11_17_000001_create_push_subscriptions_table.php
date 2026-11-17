<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi pesanan baru saat aplikasi kasir tertutup (Web Push, permintaan owner 17 November 2026).
 * Aditif: satu tabel langganan push per perangkat (endpoint unik), RLS aktif tanpa policy (hanya
 * server yang mengakses), ikut terhapus bersama user atau company-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table public.push_subscriptions (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references public.companies(id) on delete cascade,
                user_id uuid not null references public.users(id) on delete cascade,
                endpoint text not null check (char_length(endpoint) between 20 and 2048),
                p256dh text not null check (char_length(p256dh) between 40 and 200),
                auth text not null check (char_length(auth) between 10 and 100),
                content_encoding text not null default 'aes128gcm' check (content_encoding in ('aes128gcm', 'aesgcm')),
                user_agent text,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now()
            )
            SQL);
        DB::statement('create unique index push_subscriptions_endpoint_unique on public.push_subscriptions (endpoint)');
        DB::statement('create index push_subscriptions_company_idx on public.push_subscriptions (company_id)');
        DB::statement('alter table public.push_subscriptions enable row level security');
    }

    public function down(): void
    {
        DB::statement('drop table if exists public.push_subscriptions');
    }
};
