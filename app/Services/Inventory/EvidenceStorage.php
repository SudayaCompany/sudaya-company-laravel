<?php

namespace App\Services\Inventory;

/** Foto bukti stok keluar/waste di bucket privat `inventory-evidence`. Dipalsukan di automated test. */
interface EvidenceStorage
{
    public function upload(string $path, string $bytes, string $contentType): void;

    public function download(string $path): ?string;

    /** @param list<string> $paths */
    public function remove(array $paths): void;
}
