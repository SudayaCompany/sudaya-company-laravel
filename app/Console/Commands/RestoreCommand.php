<?php

namespace App\Console\Commands;

use App\Services\System\DatabaseBackup;
use App\Services\System\DatabaseRestore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Memulihkan backup ke project Supabase baru (darurat: project lama rusak/terhapus).
 * Urutan lengkap ada di docs/DEPLOY_VPS.md §Restore. Menolak database yang sudah berisi data.
 */
class RestoreCommand extends Command
{
    protected $signature = 'sudaya:restore {file : File .dump dari sudaya:backup} {--force : Lewati konfirmasi}';

    protected $description = 'Memulihkan backup database ke project Supabase baru yang masih kosong';

    public function handle(DatabaseRestore $restore): int
    {
        try {
            $problems = DatabaseRestore::targetProblems();
        } catch (Throwable $error) {
            $this->error('Database tujuan tidak bisa diperiksa: '.class_basename($error));

            return self::FAILURE;
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $env = DatabaseBackup::environment((array) config('database.connections.'.config('database.default')));
        $this->line("Tujuan: {$env['PGUSER']}@{$env['PGHOST']} (database {$env['PGDATABASE']})");
        if (! $this->option('force') && ! $this->confirm('Pulihkan backup '.basename((string) $this->argument('file')).' ke database ini?')) {
            $this->line('Dibatalkan.');

            return self::FAILURE;
        }

        try {
            $restore->run(
                (string) $this->argument('file'),
                $env,
                (string) config('sudaya.pg_restore', 'pg_restore'),
                (string) config('sudaya.psql', 'psql'),
            );
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore selesai. Lanjutkan: php artisan sudaya:storage-setup, lalu php artisan sudaya:doctor.');

        return self::SUCCESS;
    }
}
