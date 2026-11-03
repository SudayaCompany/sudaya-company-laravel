<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Upload dan hapus foto menu lewat service role, hanya dari server. Bucket tidak
 * punya policy untuk anon atau authenticated, jadi browser tidak bisa menulis
 * langsung; hanya aksi yang sudah lolos cek permission.
 */
final class SupabaseMenuImageStorage implements MenuImageStorage
{
    public function upload(string $path, string $bytes, string $contentType): void
    {
        $response = $this->http()
            ->withHeaders(['x-upsert' => 'false', 'cache-control' => 'max-age=31536000'])
            ->withBody($bytes, $contentType)
            ->post('/storage/v1/object/'.MenuImages::BUCKET.'/'.$this->encode($path));
        if ($response->failed()) {
            throw new RuntimeException('Upload foto gagal ('.$response->status().').');
        }
    }

    public function remove(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $response = $this->http()->delete('/storage/v1/object/'.MenuImages::BUCKET, ['prefixes' => $paths]);
        if ($response->failed()) {
            throw new RuntimeException('Hapus foto gagal ('.$response->status().').');
        }
    }

    private function http(): PendingRequest
    {
        $key = (string) config('services.supabase.service_role_key');

        return Http::baseUrl((string) config('services.supabase.url'))
            ->withHeaders(['apikey' => $key])
            ->withToken($key)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    private function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
