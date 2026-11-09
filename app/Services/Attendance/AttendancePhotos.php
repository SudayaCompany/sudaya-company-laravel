<?php

namespace App\Services\Attendance;

use App\Exceptions\AccessException;
use App\Services\Catalog\MenuImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Foto selfie absen: wajib ada, divalidasi dari isi file, disimpan dengan nama acak. */
final class AttendancePhotos
{
    /** Bucket privat yang sama dengan bukti tugas luar (dibuat skrip setup Storage). */
    public const BUCKET = 'attendance-evidence';

    /** Batas bucket 3 MB; browser sudah memperkecil foto sebelum dikirim (±100–300 KB). */
    public const MAX_BYTES = 3 * 1024 * 1024;

    /** Keputusan owner 9 November (G5): foto dihapus otomatis setelah 90 hari. */
    public const RETENTION_DAYS = 90;

    /** @return array{bytes: string, ext: string, contentType: string} */
    public static function read(mixed $file): array
    {
        if (! $file instanceof UploadedFile || $file->getSize() === 0) {
            throw new AccessException('invalid', 'Foto selfie wajib diambil.');
        }
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new AccessException('invalid', 'Ukuran foto maksimal 3 MB.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $type = MenuImages::detect($bytes);
        if ($type === null) {
            throw new AccessException('invalid', 'Format foto harus JPG, PNG, atau WebP.');
        }

        return ['bytes' => $bytes, ...$type];
    }

    /** selfie/<company>/<karyawan>/<YYYY-MM>/<uuid>.<ext>; nama dari client tidak pernah dipakai. */
    public static function path(string $companyId, string $employeeId, string $businessDate, string $ext): string
    {
        return "selfie/{$companyId}/{$employeeId}/".substr($businessDate, 0, 7).'/'.Str::uuid().".{$ext}";
    }

    /** Tipe konten dari ekstensi path yang dibuat aplikasi sendiri. */
    public static function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
