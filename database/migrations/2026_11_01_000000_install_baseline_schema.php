<?php

use App\Support\Database\BaselineSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Schema dasar untuk project Supabase baru (staging/production).
 *
 * Isinya 10 migration Drizzle yang dulu dijalankan dari repo Next.js, disalin apa
 * adanya di `database/schema/baseline`: 18 enum, 41 tabel `public` (semua RLS tanpa
 * policy), fungsi + trigger, dan 2 job pg_cron. Semuanya dipasang dalam satu transaksi.
 *
 * Database yang schema-nya sudah lengkap (development) dilewati tanpa perubahan.
 * Schema setengah jadi dan project yang bukan Supabase ditolak sebelum ada perubahan.
 */
return new class extends Migration
{
    /** Transaksi diatur sendiri: RLS tabel `migrations` harus tetap aktif walau pemasangan ditolak. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Schema Sudaya hanya untuk PostgreSQL Supabase (DB_CONNECTION=pgsql).');
        }

        BaselineSchema::protectMigrationsTable();

        $state = BaselineSchema::state();
        if ($state === BaselineSchema::INSTALLED) {
            return;
        }
        if ($state === BaselineSchema::PARTIAL) {
            throw new RuntimeException('Sebagian tabel Sudaya sudah ada di database ini, tetapi tidak lengkap. Tidak ada yang diubah. Pakai project Supabase yang benar-benar kosong.');
        }

        $missing = BaselineSchema::missingPrerequisites();
        if ($missing !== []) {
            throw new RuntimeException('Project Supabase belum siap. Tidak ada yang diubah. '.implode(' ', $missing));
        }

        DB::transaction(fn () => BaselineSchema::install());
    }

    public function down(): void
    {
        // Sengaja kosong: schema dasar memuat seluruh data bisnis dan tidak pernah dihapus lewat rollback.
    }
};
