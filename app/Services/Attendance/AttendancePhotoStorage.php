<?php

namespace App\Services\Attendance;

/** Penyimpanan foto absensi di bucket privat. Diganti implementasi palsu di automated test. */
interface AttendancePhotoStorage
{
    public function upload(string $path, string $bytes, string $contentType): void;

    /** Isi file; null bila tidak ada (mis. sudah dihapus retensi). */
    public function download(string $path): ?string;

    /** @param list<string> $paths */
    public function remove(array $paths): void;
}
