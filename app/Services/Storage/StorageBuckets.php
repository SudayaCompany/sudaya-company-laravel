<?php

namespace App\Services\Storage;

use App\Services\Attendance\AttendancePhotos;
use App\Services\Catalog\MenuImages;
use App\Services\Inventory\SupabaseEvidenceStorage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bucket Supabase Storage yang dibutuhkan aplikasi, sama dengan `npm run storage:setup`
 * di repo Next.js (`scripts/storage-setup.ts`):
 * - `menu-images`: publik (foto menu untuk tamu), maks 2 MB;
 * - `inventory-evidence` dan `attendance-evidence`: privat, maks 3 MB, dibuka lewat server.
 *
 * Semua upload lewat service role dari server. Tidak ada policy anon/authenticated.
 */
final class StorageBuckets
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const PRIVATE_MAX_BYTES = 3 * 1024 * 1024;

    /** @return array<string, array{public: bool, file_size_limit: int, allowed_mime_types: list<string>}> */
    public static function expected(): array
    {
        return [
            MenuImages::BUCKET => ['public' => true, 'file_size_limit' => MenuImages::MAX_BYTES, 'allowed_mime_types' => MenuImages::TYPES],
            SupabaseEvidenceStorage::BUCKET => ['public' => false, 'file_size_limit' => self::PRIVATE_MAX_BYTES, 'allowed_mime_types' => self::IMAGE_TYPES],
            AttendancePhotos::BUCKET => ['public' => false, 'file_size_limit' => AttendancePhotos::MAX_BYTES, 'allowed_mime_types' => self::IMAGE_TYPES],
        ];
    }

    /**
     * Bandingkan bucket di project dengan yang dibutuhkan. Bila `$apply`, bucket yang belum
     * ada dibuat dan yang berbeda diselaraskan; tanpa `$apply` hanya membaca.
     *
     * @return list<array{bucket: string, status: 'ok'|'created'|'updated'|'missing'|'different', detail: string}>
     */
    public function sync(bool $apply): array
    {
        $results = [];
        foreach (self::expected() as $id => $want) {
            $have = $this->find($id);
            if ($have === null) {
                if ($apply) {
                    $this->write($this->http()->post('/storage/v1/bucket', ['id' => $id, 'name' => $id, ...$want]), $id);
                }
                $results[] = ['bucket' => $id, 'status' => $apply ? 'created' : 'missing', 'detail' => self::describe($want)];

                continue;
            }
            $diff = self::differences($have, $want);
            if ($diff === []) {
                $results[] = ['bucket' => $id, 'status' => 'ok', 'detail' => self::describe($want)];

                continue;
            }
            if ($apply) {
                $this->write($this->http()->put('/storage/v1/bucket/'.rawurlencode($id), ['id' => $id, 'name' => $id, ...$want]), $id);
            }
            $results[] = ['bucket' => $id, 'status' => $apply ? 'updated' : 'different', 'detail' => implode(', ', $diff)];
        }

        return $results;
    }

    /**
     * Bucket publik selain `menu-images`. Data absensi dan stok wajib privat.
     *
     * @return list<string>
     */
    public function unexpectedPublic(): array
    {
        $response = $this->http()->get('/storage/v1/bucket');
        if ($response->failed()) {
            throw new RuntimeException('Daftar bucket tidak bisa dibaca ('.$response->status().').');
        }

        return array_values(array_map(
            fn (array $bucket) => (string) $bucket['id'],
            array_filter((array) $response->json(), fn ($bucket) => is_array($bucket) && ($bucket['public'] ?? false) === true && ($bucket['id'] ?? null) !== MenuImages::BUCKET),
        ));
    }

    /**
     * @param  array<string, mixed>  $have
     * @param  array{public: bool, file_size_limit: int, allowed_mime_types: list<string>}  $want
     * @return list<string>
     */
    public static function differences(array $have, array $want): array
    {
        $diff = [];
        if (($have['public'] ?? null) !== $want['public']) {
            $diff[] = $want['public'] ? 'harus publik' : 'harus privat';
        }
        if ((int) ($have['file_size_limit'] ?? 0) !== $want['file_size_limit']) {
            $diff[] = 'batas ukuran '.self::megabytes($want['file_size_limit']);
        }
        $types = array_map('strval', (array) ($have['allowed_mime_types'] ?? []));
        sort($types);
        $wantTypes = $want['allowed_mime_types'];
        sort($wantTypes);
        if ($types !== $wantTypes) {
            $diff[] = 'jenis file '.implode('/', $want['allowed_mime_types']);
        }

        return $diff;
    }

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        $response = $this->http()->get('/storage/v1/bucket/'.rawurlencode($id));
        if ($response->successful()) {
            return (array) $response->json();
        }
        // Storage API lama menjawab 400 dengan statusCode "404" di body untuk bucket yang tidak ada.
        if ($response->status() === 404 || ($response->status() === 400 && (string) $response->json('statusCode') === '404')) {
            return null;
        }

        throw new RuntimeException("Bucket {$id} tidak bisa dibaca ({$response->status()}). Periksa SUPABASE_URL dan SUPABASE_SERVICE_ROLE_KEY.");
    }

    private function write(Response $response, string $id): void
    {
        if ($response->failed()) {
            throw new RuntimeException("Bucket {$id} gagal disimpan ({$response->status()}).");
        }
    }

    /** @param  array{public: bool, file_size_limit: int, allowed_mime_types: list<string>}  $want */
    private static function describe(array $want): string
    {
        return ($want['public'] ? 'publik' : 'privat').', maks '.self::megabytes($want['file_size_limit']).', JPG/PNG/WebP';
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',').' MB';
    }

    private function http(): PendingRequest
    {
        $key = (string) config('services.supabase.service_role_key');
        if ($key === '' || (string) config('services.supabase.url') === '') {
            throw new RuntimeException('SUPABASE_URL dan SUPABASE_SERVICE_ROLE_KEY wajib diisi.');
        }

        return Http::baseUrl((string) config('services.supabase.url'))
            ->withHeaders(['apikey' => $key])
            ->withToken($key)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }
}
