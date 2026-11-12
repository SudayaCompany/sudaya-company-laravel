<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageBuckets;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menyiapkan bucket Supabase Storage (foto menu, bukti stok, foto absen) di project baru.
 * Aman diulang: bucket yang sudah sesuai tidak diubah. Pengganti `npm run storage:setup`
 * dari repo Next.js.
 */
class StorageSetupCommand extends Command
{
    protected $signature = 'sudaya:storage-setup {--check : Hanya memeriksa, tanpa membuat atau mengubah bucket}';

    protected $description = 'Membuat/menyelaraskan bucket Supabase Storage yang dibutuhkan aplikasi';

    public function handle(StorageBuckets $buckets): int
    {
        $apply = ! $this->option('check');
        $label = [
            'ok' => 'sesuai',
            'created' => 'dibuat',
            'updated' => 'diselaraskan',
            'missing' => 'BELUM ADA',
            'different' => 'BERBEDA',
        ];

        try {
            $results = $buckets->sync($apply);
            $public = $buckets->unexpectedPublic();
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $row) {
            $line = "Bucket {$row['bucket']}: {$label[$row['status']]} ({$row['detail']})";
            in_array($row['status'], ['missing', 'different'], true) ? $this->warn($line) : $this->info($line);
        }
        if ($public !== []) {
            $this->error('Bucket publik selain menu-images: '.implode(', ', $public).'. Bucket absensi/stok wajib privat; ubah di Supabase Dashboard → Storage.');

            return self::FAILURE;
        }

        $pending = array_filter($results, fn (array $row) => in_array($row['status'], ['missing', 'different'], true));
        if ($pending !== []) {
            $this->warn('Jalankan tanpa --check untuk membuat/menyelaraskan bucket.');

            return self::FAILURE;
        }
        $this->info('Semua bucket siap.');

        return self::SUCCESS;
    }
}
