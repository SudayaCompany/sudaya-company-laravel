<?php

namespace Tests\Unit;

use App\Services\System\SystemHealth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `php artisan sudaya:doctor`: pemeriksaan tidak pernah berhenti di tengah dan menilai dengan benar. */
class SystemHealthTest extends TestCase
{
    /** @return array<string, array{status: string, message: string, hint: ?string}> */
    private function checks(): array
    {
        $byLabel = [];
        foreach (app(SystemHealth::class)->run() as $check) {
            $byLabel[$check['group'].'/'.$check['label']] = $check;
        }

        return $byLabel;
    }

    public function test_status_terburuk_menentukan_hasil(): void
    {
        $this->assertSame(SystemHealth::OK, SystemHealth::worst([['status' => 'ok'], ['status' => 'ok']]));
        $this->assertSame(SystemHealth::WARN, SystemHealth::worst([['status' => 'ok'], ['status' => 'warn']]));
        $this->assertSame(SystemHealth::FAIL, SystemHealth::worst([['status' => 'warn'], ['status' => 'fail'], ['status' => 'ok']]));
    }

    public function test_database_bukan_postgres_dan_supabase_kosong_gagal_tanpa_menghentikan_pemeriksaan(): void
    {
        config(['services.supabase.url' => '', 'services.supabase.publishable_key' => '', 'services.supabase.service_role_key' => '']);

        $checks = $this->checks();

        $this->assertSame(SystemHealth::FAIL, $checks['Konfigurasi/DB_CONNECTION']['status']);
        $this->assertSame(SystemHealth::FAIL, $checks['Database/Schema']['status'], 'error SQLite tertangkap sebagai gagal');
        $this->assertSame(SystemHealth::FAIL, $checks['Supabase/Kunci API']['status']);
        $this->assertArrayHasKey('Operasional/Cron Laravel', $checks, 'pemeriksaan sesudah kegagalan tetap berjalan');
        foreach ($checks as $label => $check) {
            $this->assertSame($check['status'] === SystemHealth::OK, $check['hint'] === null, "petunjuk hanya untuk yang belum OK: {$label}");
        }
    }

    public function test_kunci_jwt_simetris_saja_ditandai_gagal_login(): void
    {
        config(['services.supabase.url' => 'https://abcdproject.supabase.co', 'services.supabase.publishable_key' => 'pk', 'services.supabase.service_role_key' => 'sk']);
        Http::fake([
            'abcdproject.supabase.co/auth/v1/.well-known/jwks.json' => Http::sequence()
                ->push(['keys' => [['kty' => 'oct', 'alg' => 'HS256']]])
                ->push(['keys' => [['kty' => 'EC', 'alg' => 'ES256', 'kid' => 'a']]]),
            'abcdproject.supabase.co/storage/v1/bucket*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $this->assertSame(SystemHealth::FAIL, $this->checks()['Supabase/Login (JWKS)']['status']);
        $this->assertSame(SystemHealth::OK, $this->checks()['Supabase/Login (JWKS)']['status']);
    }

    public function test_db_url_dan_supabase_url_beda_project_terdeteksi(): void
    {
        config([
            'services.supabase.url' => 'https://projekbaru.supabase.co', 'services.supabase.publishable_key' => 'pk', 'services.supabase.service_role_key' => 'sk',
            'database.connections.pgsql.url' => 'postgresql://postgres.projeklama:rahasia@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres',
        ]);
        Http::fake(['*' => Http::response(['keys' => []])]);

        $check = $this->checks()['Supabase/Project sama'];

        $this->assertSame(SystemHealth::FAIL, $check['status']);
        $this->assertStringNotContainsString('rahasia', json_encode($this->checks()), 'password DB tidak pernah tampil');
    }
}
