<?php

namespace App\Services\System;

use App\Support\Database\BaselineSchema;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Memulihkan backup `sudaya:backup` ke project Supabase BARU yang schema-nya sudah dipasang
 * (`php artisan migrate --force`) tetapi belum berisi data (tanpa `db:seed`, tanpa akun).
 *
 * Isi backup diubah menjadi SQL (`pg_restore --data-only`) lalu dijalankan `psql` dalam satu
 * transaksi dengan `session_replication_role = replica` (cara restore resmi Supabase), jadi
 * urutan foreign key tidak berpengaruh dan kegagalan tidak meninggalkan data setengah jadi.
 */
final class DatabaseRestore
{
    /**
     * Alasan target belum boleh menerima restore (kosong = boleh).
     *
     * @return list<string>
     */
    public static function targetProblems(): array
    {
        $problems = [];
        if (BaselineSchema::state() !== BaselineSchema::INSTALLED) {
            $problems[] = 'Schema belum terpasang: jalankan php artisan migrate --force dulu.';

            return $problems;
        }
        if (DB::table('companies')->exists() || DB::table('users')->exists()) {
            $problems[] = 'Database tujuan sudah berisi data (company/akun). Restore hanya ke project baru yang kosong; jangan jalankan db:seed sebelum restore.';
        }
        if ((int) DB::scalar('select count(*) from auth.users') > 0) {
            $problems[] = 'Supabase Auth tujuan sudah berisi akun. Pakai project baru tanpa akun.';
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $env  DatabaseBackup::environment()
     * @param  string|list<string>  $pgRestore
     * @param  string|list<string>  $psql
     */
    public function run(string $file, array $env, string|array $pgRestore = 'pg_restore', string|array $psql = 'psql'): void
    {
        if (! is_file($file) || (int) filesize($file) === 0) {
            throw new RuntimeException("File backup {$file} tidak ditemukan.");
        }
        $sql = tempnam(storage_path('app'), 'restore-');
        if ($sql === false) {
            throw new RuntimeException('File sementara tidak bisa dibuat.');
        }
        @chmod($sql, 0600);

        try {
            $out = fopen($sql, 'wb');
            $in = fopen($file, 'rb');
            $error = '';
            $convert = new Process([...(array) $pgRestore, '--data-only', '--no-owner', '--no-privileges', '--file=-'], null, $env, $in, 1800);
            try {
                $convert->run(function (string $type, string $buffer) use ($out, &$error) {
                    $type === Process::OUT ? fwrite($out, $buffer) : $error .= $buffer;
                });
            } finally {
                fclose($out);
                is_resource($in) && fclose($in);
            }
            if (! $convert->isSuccessful()) {
                throw new RuntimeException('File backup tidak bisa dibaca pg_restore: '.DatabaseBackup::clean($error, $env));
            }

            $input = fopen($sql, 'rb');
            $apply = new Process(
                [...(array) $psql, '--no-psqlrc', '--quiet', '--single-transaction', '--set=ON_ERROR_STOP=1', '--command=SET session_replication_role = replica', '--file=-'],
                null,
                $env + ['PGCONNECT_TIMEOUT' => '15'],
                $input,
                3600,
            );
            try {
                $apply->run();
            } finally {
                is_resource($input) && fclose($input);
            }
            if (! $apply->isSuccessful()) {
                throw new RuntimeException('Restore dibatalkan, tidak ada data yang masuk: '.DatabaseBackup::clean($apply->getErrorOutput(), $env));
            }
        } finally {
            @unlink($sql);
        }
    }
}
