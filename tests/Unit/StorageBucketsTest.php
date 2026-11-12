<?php

namespace Tests\Unit;

use App\Services\Storage\StorageBuckets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** `php artisan sudaya:storage-setup`: bucket Supabase Storage untuk project baru. */
class StorageBucketsTest extends TestCase
{
    private const MENU = ['id' => 'menu-images', 'public' => true, 'file_size_limit' => 2097152, 'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp']];

    private const INVENTORY = ['id' => 'inventory-evidence', 'public' => false, 'file_size_limit' => 3145728, 'allowed_mime_types' => ['image/webp', 'image/png', 'image/jpeg']];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://proyek.supabase.co', 'services.supabase.service_role_key' => 'kunci-uji']);
    }

    public function test_bucket_sama_dengan_kebijakan_repo_nextjs(): void
    {
        $this->assertSame([
            'menu-images' => ['public' => true, 'file_size_limit' => 2097152, 'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp']],
            'inventory-evidence' => ['public' => false, 'file_size_limit' => 3145728, 'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp']],
            'attendance-evidence' => ['public' => false, 'file_size_limit' => 3145728, 'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp']],
        ], StorageBuckets::expected());
    }

    public function test_project_baru_bucket_dibuat_dan_yang_sesuai_tidak_disentuh(): void
    {
        Http::fake([
            'proyek.supabase.co/storage/v1/bucket/menu-images' => Http::response(self::MENU),
            // Urutan jenis file berbeda tetap dianggap sama.
            'proyek.supabase.co/storage/v1/bucket/inventory-evidence' => Http::response(self::INVENTORY),
            'proyek.supabase.co/storage/v1/bucket/attendance-evidence' => Http::response(['statusCode' => '404', 'error' => 'Bucket not found'], 400),
            'proyek.supabase.co/storage/v1/bucket' => Http::response(['name' => 'attendance-evidence']),
        ]);

        $results = (new StorageBuckets)->sync(apply: true);

        $this->assertSame(['ok', 'ok', 'created'], array_column($results, 'status'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://proyek.supabase.co/storage/v1/bucket'
            && $request['id'] === 'attendance-evidence' && $request['public'] === false && $request['file_size_limit'] === 3145728
            && $request->hasHeader('apikey', 'kunci-uji') && $request->hasHeader('Authorization', 'Bearer kunci-uji'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_bucket_berbeda_diselaraskan_dan_mode_cek_tidak_menulis(): void
    {
        Http::fake([
            'proyek.supabase.co/storage/v1/bucket/menu-images' => Http::response([...self::MENU, 'file_size_limit' => 10485760]),
            'proyek.supabase.co/storage/v1/bucket/inventory-evidence' => Http::response([...self::INVENTORY, 'public' => true]),
            'proyek.supabase.co/storage/v1/bucket/attendance-evidence' => Http::response(['error' => 'not found'], 404),
            '*' => Http::response(['message' => 'ok']),
        ]);

        $check = (new StorageBuckets)->sync(apply: false);
        $this->assertSame(['different', 'different', 'missing'], array_column($check, 'status'));
        $this->assertSame(['batas ukuran 2 MB', 'harus privat'], [$check[0]['detail'], $check[1]['detail']]);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');

        $apply = (new StorageBuckets)->sync(apply: true);
        $this->assertSame(['updated', 'updated', 'created'], array_column($apply, 'status'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://proyek.supabase.co/storage/v1/bucket/inventory-evidence' && $request['public'] === false);
    }

    public function test_bucket_publik_lain_terdeteksi_dan_error_api_tidak_ditelan(): void
    {
        Http::fake([
            'proyek.supabase.co/storage/v1/bucket' => Http::response([self::MENU, ['id' => 'foto-absen', 'public' => true], self::INVENTORY]),
            'proyek.supabase.co/storage/v1/bucket/*' => Http::response(['message' => 'Invalid JWT'], 401),
        ]);

        $this->assertSame(['foto-absen'], (new StorageBuckets)->unexpectedPublic());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Periksa SUPABASE_URL dan SUPABASE_SERVICE_ROLE_KEY');
        (new StorageBuckets)->sync(apply: false);
    }
}
