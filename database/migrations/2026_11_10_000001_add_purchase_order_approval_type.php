<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 PRD §27 (purchasing): pengajuan pembelian butuh persetujuan. Aditif saja: menambah
 * nilai `purchase_order` pada enum `approval_type`. Tabel dan data lain tidak diubah.
 */
return new class extends Migration
{
    /** ALTER TYPE … ADD VALUE tidak bisa dipakai di transaksi yang sama; jalankan di luar transaksi. */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("alter type public.approval_type add value if not exists 'purchase_order'");
    }

    public function down(): void
    {
        // Nilai enum tidak dihapus (Postgres harus membuat ulang tipe); nilai yang tidak dipakai tidak berdampak.
    }
};
