<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Absensi harian GPS + foto selfie (M7-lite, keputusan owner 8 November opsi A; migration
 * disetujui 9 November, G6). Aditif saja: menambah nilai `gps_selfie` pada enum
 * `attendance_method`. Tabel dan data lain tidak diubah.
 */
return new class extends Migration
{
    /** ALTER TYPE … ADD VALUE tidak bisa dipakai di transaksi yang sama; jalankan di luar transaksi. */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("alter type public.attendance_method add value if not exists 'gps_selfie'");
    }

    public function down(): void
    {
        // Postgres tidak bisa menghapus nilai enum tanpa membuat ulang tipe dan semua kolomnya.
        // Nilai yang tidak dipakai tidak berdampak, jadi sengaja dibiarkan (aditif).
    }
};
