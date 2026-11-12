<?php

namespace Tests\Unit;

use App\Services\System\DatabaseBackup;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** `php artisan sudaya:backup`: pg_dump public + auth, dicek pg_restore, rotasi, tanpa bocor kredensial. */
class DatabaseBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backup-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function tools(): array
    {
        $fixtures = base_path('tests/Fixtures/pg');

        return [[PHP_BINARY, $fixtures.'/fake-pg-dump.php'], [PHP_BINARY, $fixtures.'/fake-pg-restore.php']];
    }

    private function env(): array
    {
        return DatabaseBackup::environment(['url' => 'postgresql://postgres.abcd:p%40ss%2Fw0rd@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres?sslmode=require']);
    }

    /** Mode pg_dump palsu lewat environment proses (putenv tidak diwariskan Symfony Process). */
    private function envWith(string $mode): array
    {
        return $this->env() + ['FAKE_PG_MODE' => $mode];
    }

    /** Jalankan backup dengan pg_dump palsu yang gagal; kembalikan error dari layanan. */
    private function failure(string $mode): RuntimeException
    {
        [$dump, $restore] = $this->tools();
        try {
            (new DatabaseBackup)->run($this->dir, 14, $this->envWith($mode), $dump, $restore);
        } catch (RuntimeException $error) {
            return $error;
        }
        $this->fail("mode {$mode} harus gagal");
    }

    public function test_kredensial_dari_db_url_dan_konfigurasi_biasa(): void
    {
        $this->assertSame([
            'PGHOST' => 'aws-0-ap-southeast-1.pooler.supabase.com',
            'PGPORT' => '5432',
            'PGUSER' => 'postgres.abcd',
            'PGPASSWORD' => 'p@ss/w0rd',
            'PGDATABASE' => 'postgres',
            'PGSSLMODE' => 'require',
        ], $this->env());
        $this->assertSame(['PGHOST' => 'db.lokal', 'PGPORT' => '6543', 'PGUSER' => 'app', 'PGPASSWORD' => 'x', 'PGDATABASE' => 'kopi', 'PGSSLMODE' => 'prefer'], DatabaseBackup::environment([
            'url' => null, 'host' => 'db.lokal', 'port' => 6543, 'username' => 'app', 'password' => 'x', 'database' => 'kopi', 'sslmode' => 'prefer',
        ]));
    }

    public function test_backup_tersimpan_dicek_dan_backup_lama_dirotasi(): void
    {
        File::ensureDirectoryExists($this->dir);
        $old = $this->dir.'/sudaya-20260101-000000.dump';
        $recent = $this->dir.'/sudaya-20261110-000000.dump';
        $other = $this->dir.'/lain-20250101-000000.dump';
        foreach ([$old, $recent, $other] as $file) {
            file_put_contents($file, 'x');
        }
        touch($old, time() - 20 * 86400);
        touch($recent, time() - 2 * 86400);
        touch($other, time() - 40 * 86400);
        [$dump, $restore] = $this->tools();

        $result = (new DatabaseBackup)->run($this->dir, 14, $this->envWith('ok'), $dump, $restore);

        $this->assertFileExists($result['file']);
        $this->assertMatchesRegularExpression('/sudaya-\d{8}-\d{6}\.dump$/', $result['file']);
        $content = (string) file_get_contents($result['file']);
        $this->assertStringStartsWith('PGDMP|--format=custom --no-owner --no-privileges --schema=public --schema=auth --exclude-table-data=public.migrations --exclude-table-data=auth.schema_migrations --exclude-table-data=auth.sessions --exclude-table-data=auth.refresh_tokens', $content);
        $this->assertStringContainsString('|aws-0-ap-southeast-1.pooler.supabase.com|', $content);
        $this->assertStringNotContainsString('p@ss/w0rd', $content, 'password tidak lewat argumen');
        $this->assertSame(3, $result['tables']);
        $this->assertSame(['sudaya-20260101-000000.dump'], $result['removed']);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other, 'file tanpa awalan sudaya- tidak disentuh walau lama');
        $this->assertSame([], glob($this->dir.'/*.part'));
    }

    public function test_pg_dump_gagal_tanpa_file_sisa_dan_password_disamarkan(): void
    {
        $message = $this->failure('fail')->getMessage();

        $this->assertStringContainsString('pg_dump gagal (exit 1)', $message);
        $this->assertStringNotContainsString('p@ss/w0rd', $message);
        $this->assertStringContainsString('***', $message);
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function test_file_dump_rusak_ditolak_dan_dibuang(): void
    {
        $this->assertStringContainsString('File backup rusak', $this->failure('corrupt')->getMessage());
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function test_perintah_menolak_tanpa_folder_backup(): void
    {
        config(['sudaya.backup_dir' => null]);

        $this->artisan('sudaya:backup')->expectsOutputToContain('Isi BACKUP_DIR')->assertFailed();
    }
}
