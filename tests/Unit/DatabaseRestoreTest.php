<?php

namespace Tests\Unit;

use App\Services\System\DatabaseBackup;
use App\Services\System\DatabaseRestore;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** `php artisan sudaya:restore`: data-only dalam satu transaksi dengan replica role, tanpa sisa file. */
class DatabaseRestoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/restore-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function env(string $mode = 'ok'): array
    {
        return DatabaseBackup::environment(['url' => 'postgresql://postgres.baru:R4hasia%21@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres'])
            + ['FAKE_PG_MODE' => $mode, 'FAKE_PSQL_LOG' => $this->dir.'/psql.log'];
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function tools(): array
    {
        $fixtures = base_path('tests/Fixtures/pg');

        return [[PHP_BINARY, $fixtures.'/fake-pg-restore.php'], [PHP_BINARY, $fixtures.'/fake-psql.php']];
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return glob(storage_path('app/restore-*')) ?: [];
    }

    public function test_data_dipulihkan_dalam_satu_transaksi_tanpa_cek_foreign_key(): void
    {
        file_put_contents($this->dir.'/backup.dump', 'PGDMP|isi');
        $before = $this->leftovers();
        [$pgRestore, $psql] = $this->tools();

        (new DatabaseRestore)->run($this->dir.'/backup.dump', $this->env(), $pgRestore, $psql);

        [$args, $sql] = explode("\n---\n", (string) file_get_contents($this->dir.'/psql.log'), 2);
        $this->assertSame('--no-psqlrc --quiet --single-transaction --set=ON_ERROR_STOP=1 --command=SET session_replication_role = replica --file=-', $args);
        $this->assertStringContainsString('-- args: --data-only --no-owner --no-privileges --file=-', $sql);
        $this->assertStringContainsString('COPY public.orders (id) FROM stdin;', $sql);
        $this->assertSame($before, $this->leftovers(), 'file SQL sementara dihapus');
    }

    public function test_gagal_diterapkan_pesan_tanpa_password_dan_file_sementara_dihapus(): void
    {
        file_put_contents($this->dir.'/backup.dump', 'PGDMP|isi');
        $before = $this->leftovers();
        [$pgRestore, $psql] = $this->tools();

        try {
            (new DatabaseRestore)->run($this->dir.'/backup.dump', $this->env('psqlfail'), $pgRestore, $psql);
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Restore dibatalkan, tidak ada data yang masuk', $error->getMessage());
            $this->assertStringNotContainsString('R4hasia!', $error->getMessage());
            $this->assertSame($before, $this->leftovers());

            return;
        }
        $this->fail('restore harus gagal');
    }

    public function test_file_bukan_backup_ditolak_sebelum_menyentuh_database(): void
    {
        file_put_contents($this->dir.'/bukan.dump', 'teks biasa');
        [$pgRestore, $psql] = $this->tools();

        try {
            (new DatabaseRestore)->run($this->dir.'/bukan.dump', $this->env(), $pgRestore, $psql);
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('tidak bisa dibaca pg_restore', $error->getMessage());
            $this->assertFileDoesNotExist($this->dir.'/psql.log', 'psql tidak dijalankan');

            return;
        }
        $this->fail('file rusak harus ditolak');
    }
}
