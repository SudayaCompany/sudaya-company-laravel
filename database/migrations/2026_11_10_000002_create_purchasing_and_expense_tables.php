<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 PRD §26–27 (supplier & purchase order) dan §59 (pengeluaran). Aditif saja: empat
 * tabel baru, RLS aktif tanpa policy (hanya server yang mengakses), sama dengan tabel bisnis lain.
 * Jumlah uang dalam rupiah bulat (bigint); kuantitas dalam satuan dasar item stok (numeric 14,3).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table public.suppliers (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references public.companies(id),
                name text not null check (char_length(name) between 2 and 120),
                contact_name text,
                phone text,
                email text,
                products text,
                payment_term text,
                notes text,
                is_active boolean not null default true,
                created_by uuid references public.users(id),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now()
            )
            SQL);
        DB::statement('create unique index suppliers_company_name_unique on public.suppliers (company_id, lower(name))');
        DB::statement('alter table public.suppliers enable row level security');

        DB::statement(<<<'SQL'
            create table public.purchase_orders (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references public.companies(id),
                brand_id uuid not null references public.brands(id),
                outlet_id uuid not null references public.outlets(id),
                supplier_id uuid not null references public.suppliers(id),
                number text not null,
                status text not null default 'draft'
                    check (status in ('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'received')),
                expected_date date,
                notes text,
                total bigint not null default 0 check (total >= 0),
                approval_id uuid references public.approvals(id),
                received_at timestamptz,
                received_by uuid references public.users(id),
                created_by uuid not null references public.users(id),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint purchase_orders_outlet_number_unique unique (outlet_id, number)
            )
            SQL);
        DB::statement('create index purchase_orders_supplier_idx on public.purchase_orders (supplier_id, created_at)');
        DB::statement('alter table public.purchase_orders enable row level security');

        DB::statement(<<<'SQL'
            create table public.purchase_order_items (
                id uuid primary key default gen_random_uuid(),
                purchase_order_id uuid not null references public.purchase_orders(id) on delete cascade,
                item_id uuid not null references public.inventory_items(id),
                quantity numeric(14, 3) not null check (quantity > 0),
                unit_cost numeric(14, 4) not null default 0 check (unit_cost >= 0),
                received_quantity numeric(14, 3) check (received_quantity >= 0),
                movement_id uuid references public.inventory_transactions(id),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint purchase_order_items_item_unique unique (purchase_order_id, item_id)
            )
            SQL);
        DB::statement('alter table public.purchase_order_items enable row level security');

        DB::statement(<<<'SQL'
            create table public.expenses (
                id uuid primary key default gen_random_uuid(),
                company_id uuid not null references public.companies(id),
                brand_id uuid not null references public.brands(id),
                outlet_id uuid not null references public.outlets(id),
                expense_date date not null,
                category text not null
                    check (category in ('transport', 'parkir', 'pembelian_kecil', 'maintenance', 'kebersihan', 'lainnya')),
                amount bigint not null check (amount > 0),
                description text not null,
                evidence_path text,
                approval_id uuid references public.approvals(id),
                created_by uuid not null references public.users(id),
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now()
            )
            SQL);
        DB::statement('create index expenses_outlet_date_idx on public.expenses (outlet_id, expense_date)');
        DB::statement('alter table public.expenses enable row level security');
    }

    public function down(): void
    {
        DB::statement('drop table if exists public.expenses');
        DB::statement('drop table if exists public.purchase_order_items');
        DB::statement('drop table if exists public.purchase_orders');
        DB::statement('drop table if exists public.suppliers');
    }
};
