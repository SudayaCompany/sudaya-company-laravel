<?php

namespace App\Services\Attendance;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Foto absensi lewat service role, hanya dari server. Bucket `attendance-evidence` privat
 * tanpa policy anon/authenticated: browser tidak bisa membaca atau menulis langsung, dan
 * foto hanya keluar lewat route berizin yang meneruskan isinya.
 */
final class SupabaseAttendancePhotoStorage implements AttendancePhotoStorage
{
    public function upload(string $path, string $bytes, string $contentType): void
    {
        $response = $this->http()
            ->withHeaders(['x-upsert' => 'false'])
            ->withBody($bytes, $contentType)
            ->post('/storage/v1/object/'.AttendancePhotos::BUCKET.'/'.$this->encode($path));
        if ($response->failed()) {
            throw new RuntimeException('Upload foto absen gagal ('.$response->status().').');
        }
    }

    public function download(string $path): ?string
    {
        $response = $this->http()->accept('*/*')->get('/storage/v1/object/authenticated/'.AttendancePhotos::BUCKET.'/'.$this->encode($path));
        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException('Foto absen tidak bisa dibaca ('.$response->status().').');
        }

        return $response->body();
    }

    public function remove(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $response = $this->http()->delete('/storage/v1/object/'.AttendancePhotos::BUCKET, ['prefixes' => $paths]);
        if ($response->failed()) {
            throw new RuntimeException('Hapus foto absen gagal ('.$response->status().').');
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
