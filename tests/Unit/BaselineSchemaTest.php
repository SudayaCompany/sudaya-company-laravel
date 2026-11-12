<?php

namespace Tests\Unit;

use App\Support\Database\BaselineSchema;
use Tests\TestCase;

/**
 * Schema dasar (salinan 10 migration Drizzle) yang dipasang `php artisan migrate` di project
 * Supabase kosong. Isi file dikunci: mengubahnya berarti database baru berbeda dari yang
 * sudah berjalan, jadi perubahan schema wajib lewat migration Laravel baru.
 */
class BaselineSchemaTest extends TestCase
{
    /** sha256 isi file (akhir baris LF). 0003 dan 0006 aslinya CRLF di repo Next.js. */
    private const HASHES = [
        '0000_init.sql' => 'ec5691984658310ba2c278b7543842ec5b143fdf893ceb3a2da0286dbf091021',
        '0001_catalog_slug_sku_soft_delete.sql' => '03d05ba54b7252333ee86c29708e05875f0ee2b74b13153118e843b6d09f760d',
        '0002_order_access_token.sql' => '108475ad7d764b7633a36cf89d4cbe917d600ef230a8839a1e47134d84290eb7',
        '0003_hardening_inventory.sql' => '7f613583ea4f7a3dfe5e6e7be8ce7195b4feb41cc31bffe792aca828b5e605f0',
        '0004_ops_functions.sql' => '5ed426dcc9895a44e2f3fdd9cdd65ea2b8ceb52ed2c2230ba6202dc588a12a48',
        '0005_inventory_document_ref.sql' => '78331a2ffd9ee461d9abdc658d13b342cb7a0e93c3f736764324e2b4f80e2539',
        '0006_attendance.sql' => '5c4fd67ece7a55b4d63f218c89bd063f38725a42069e1f90db57220f3cf7a80a',
        '0007_audit_logs_immutable.sql' => '1014e0529895594581b8cc1522f2dd0bf9a1867e270ba27fd59b9344d28fc956',
        '0008_order_request_hash.sql' => '7d4cade01077e011569a15a52ff1626e59a91a59848b12d6ca3c8ed277984c5c',
        '0009_cash_movements.sql' => 'c55763d1db89cc7941d627f897582a74021b942338f069ef7b90fd9d3af3f490',
    ];

    public function test_isi_file_schema_dasar_tidak_berubah(): void
    {
        $this->assertSame(array_keys(self::HASHES), BaselineSchema::FILES);
        foreach (self::HASHES as $file => $hash) {
            $sql = (string) file_get_contents(BaselineSchema::directory().'/'.$file);
            $this->assertSame($hash, hash('sha256', str_replace("\r\n", "\n", $sql)), $file);
        }
        $extra = array_diff(array_map('basename', glob(BaselineSchema::directory().'/*.sql') ?: []), BaselineSchema::FILES);
        $this->assertSame([], array_values($extra), 'file schema dasar di luar daftar');
    }

    public function test_jumlah_statement_tabel_dan_enum(): void
    {
        $this->assertCount(355, BaselineSchema::statements());
        $this->assertCount(41, BaselineSchema::tables());
        $this->assertCount(18, BaselineSchema::types());
        $this->assertContains('companies', BaselineSchema::tables());
        $this->assertContains('cash_movements', BaselineSchema::tables());
        $this->assertContains('attendance_method', BaselineSchema::types());
        // Tabel milik migration Laravel bukan bagian schema dasar.
        $this->assertNotContains('outlet_tables', BaselineSchema::tables());
        $this->assertNotContains('migrations', BaselineSchema::tables());
    }

    public function test_pemecah_statement_sama_dengan_drizzle(): void
    {
        $sql = "-- komentar pembuka\r\nCREATE TABLE \"a\" (id int);--> statement-breakpoint\r\n"
            ."-- hanya komentar\r\n--> statement-breakpoint\n"
            ."CREATE FUNCTION f() RETURNS void AS \$\$ BEGIN PERFORM 1; PERFORM 2; END; \$\$ LANGUAGE plpgsql;--> statement-breakpoint\n";

        $this->assertSame([
            "-- komentar pembuka\nCREATE TABLE \"a\" (id int);",
            'CREATE FUNCTION f() RETURNS void AS $$ BEGIN PERFORM 1; PERFORM 2; END; $$ LANGUAGE plpgsql;',
        ], BaselineSchema::split($sql));
        $this->assertSame([], BaselineSchema::split("  \n--> statement-breakpoint\n-- x\n"));
    }

    public function test_job_pg_cron_dan_fungsi_operasional_ada_di_schema_dasar(): void
    {
        $all = implode("\n", BaselineSchema::statements());

        $this->assertStringContainsString("cron.schedule('sudaya-expire-pending-orders', '* * * * *'", $all);
        $this->assertStringContainsString("cron.schedule('sudaya-prune-rate-limits', '*/10 * * * *'", $all);
        $this->assertStringContainsString('REFERENCES "auth"."users"("id")', $all);
        $this->assertStringContainsString('realtime.send(', $all);
        // Tiap tabel schema dasar mengaktifkan RLS.
        foreach (BaselineSchema::tables() as $table) {
            $this->assertStringContainsString("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY", $all, $table);
        }
    }
}
