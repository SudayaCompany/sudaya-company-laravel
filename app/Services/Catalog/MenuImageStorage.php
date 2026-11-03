<?php

namespace App\Services\Catalog;

/** Penyimpanan foto menu, terikat lewat container agar bisa diganti saat test. */
interface MenuImageStorage
{
    public function upload(string $path, string $bytes, string $contentType): void;

    /** @param list<string> $paths */
    public function remove(array $paths): void;
}
