<?php

namespace App\Services\Inventory;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Bukti stok lewat service role, hanya dari server; bucket privat tanpa policy anon/authenticated. */
final class SupabaseEvidenceStorage implements EvidenceStorage
{
    public const BUCKET = 'inventory-evidence';

    public function upload(string $path, string $bytes, string $contentType): void
    {
        $response = $this->http()->withHeaders(['x-upsert' => 'false'])->withBody($bytes, $contentType)
            ->post('/storage/v1/object/'.self::BUCKET.'/'.$this->encode($path));
        if ($response->failed()) {
            throw new RuntimeException('Upload foto bukti gagal ('.$response->status().').');
        }
    }

    public function download(string $path): ?string
    {
        $response = $this->http()->accept('*/*')->get('/storage/v1/object/authenticated/'.self::BUCKET.'/'.$this->encode($path));
        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException('Foto bukti tidak bisa dibaca ('.$response->status().').');
        }

        return $response->body();
    }

    public function remove(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $response = $this->http()->delete('/storage/v1/object/'.self::BUCKET, ['prefixes' => $paths]);
        if ($response->failed()) {
            throw new RuntimeException('Hapus foto bukti gagal ('.$response->status().').');
        }
    }

    private function http(): PendingRequest
    {
        $key = (string) config('services.supabase.service_role_key');

        return Http::baseUrl((string) config('services.supabase.url'))
            ->withHeaders(['apikey' => $key])->withToken($key)->acceptJson()->connectTimeout(5)->timeout(30);
    }

    private function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
