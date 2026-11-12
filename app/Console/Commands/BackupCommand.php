<?php

namespace App\Console\Commands;

use App\Services\System\DatabaseBackup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backup harian database ke BACKUP_DIR (cron 02.30 WIB, lihat deploy/cron/sudaya).
 * Restore: docs/DEPLOY_VPS.md §Restore.
 */
class BackupCommand extends Command
{
    protected $signature = 'sudaya:backup
        {--dir= : Folder tujuan (bawaan: BACKUP_DIR)}
        {--keep=14 : Berapa hari backup disimpan}';

    protected $description = 'Backup database (schema public + auth) dengan pg_dump ke BACKUP_DIR';

    public function handle(DatabaseBackup $backup): int
    {
        $dir = (string) ($this->option('dir') ?: config('sudaya.backup_dir'));
        if ($dir === '') {
            $this->error('Isi BACKUP_DIR di .env (mis. /var/backups/sudaya) atau pakai --dir.');

            return self::FAILURE;
        }

        try {
            $env = DatabaseBackup::environment((array) config('database.connections.'.config('database.default')));
            $result = $backup->run(
                $dir,
                max(1, (int) $this->option('keep')),
                $env,
                (string) config('sudaya.pg_dump', 'pg_dump'),
                (string) config('sudaya.pg_restore', 'pg_restore'),
            );
        } catch (Throwable $error) {
            $this->error('Backup gagal: '.$error->getMessage());

            return self::FAILURE;
        }

        $size = $result['bytes'] >= 1048576 ? sprintf('%.1f MB', $result['bytes'] / 1048576) : sprintf('%d KB', max(1, intdiv($result['bytes'], 1024)));
        $this->info("Backup tersimpan: {$result['file']} ({$size}, {$result['tables']} tabel berisi data)");
        if ($result['removed'] !== []) {
            $this->line('Backup lama dihapus: '.implode(', ', $result['removed']));
        }

        return self::SUCCESS;
    }
}
