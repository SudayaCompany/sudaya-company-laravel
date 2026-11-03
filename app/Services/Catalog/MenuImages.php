<?php

namespace App\Services\Catalog;

use App\Exceptions\AccessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Foto menu: validasi isi file, nama aman, dan URL publik bucket menu-images. */
final class MenuImages
{
    /** Satu-satunya bucket publik. Data absensi dan biometrik wajib di bucket privat. */
    public const BUCKET = 'menu-images';

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Deteksi tipe dari isi file (magic bytes), bukan dari nama atau MIME kiriman client.
     *
     * @return array{ext: string, contentType: string}|null
     */
    public static function detect(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ['ext' => 'jpg', 'contentType' => 'image/jpeg'];
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ['ext' => 'png', 'contentType' => 'image/png'];
        }
        if (strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return ['ext' => 'webp', 'contentType' => 'image/webp'];
        }

        return null;
    }

    /**
     * Null jika tidak ada file yang diunggah. Melempar AccessException jika tidak valid.
     *
     * @return array{bytes: string, ext: string, contentType: string}|null
     */
    public static function read(mixed $file): ?array
    {
        if (! $file instanceof UploadedFile || $file->getSize() === 0) {
            return null;
        }
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new AccessException('invalid', 'Ukuran foto maksimal 2 MB.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $type = self::detect($bytes);
        if ($type === null) {
            throw new AccessException('invalid', 'Format foto harus JPG, PNG, atau WebP.');
        }

        return ['bytes' => $bytes, ...$type];
    }

    /** Nama acak di folder company/brand: nama file dari client tidak pernah dipakai. */
    public static function path(string $companyId, string $brandId, string $ext): string
    {
        return "{$companyId}/{$brandId}/".Str::uuid().".{$ext}";
    }

    /** URL publik foto menu (bucket menu-images publik, hanya-baca bagi guest). */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return rtrim((string) config('services.supabase.url'), '/').'/storage/v1/object/public/'.self::BUCKET.'/'.$encoded;
    }
}
