<?php

namespace App\Support\Catalog;

final class Slug
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** "Kopi Susu Gula Aren!" → "kopi-susu-gula-aren" */
    public static function make(string $value, int $maxLength = 80): string
    {
        // Pisahkan huruf dari tanda aksen (NFKD), lalu buang tandanya: "Café" → "cafe".
        $normalized = class_exists(\Normalizer::class) ? \Normalizer::normalize($value, \Normalizer::FORM_KD) : $value;
        $ascii = preg_replace('/\p{Mn}/u', '', (string) $normalized);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $ascii)), '-');

        return rtrim(substr($slug, 0, $maxLength), '-');
    }
}
