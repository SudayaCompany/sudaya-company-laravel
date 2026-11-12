<?php

namespace App\Support;

use App\Services\Site\SiteContent;

/**
 * Meta SEO dan Open Graph untuk HTML awal (tanpa SSR, jadi harus dari server): pratinjau
 * tautan di WhatsApp/Instagram/Facebook dan mesin pencari tidak menjalankan JavaScript.
 * Hanya halaman publik yang boleh diindeks; halaman staf, kiosk, dan status pesanan tidak.
 */
final class PublicMeta
{
    /** Foto kegiatan program petani kopi (plat nomor disamarkan), dipakai sebagai gambar pratinjau. */
    public const IMAGE = '/images/program-petani-kopi-900.jpg';

    private const MAX_DESCRIPTION = 160;

    /**
     * Meta untuk path halaman, atau null bila halaman tidak boleh diindeks.
     *
     * @return array{title: string, description: string, url: string, image: string, canonical: ?string}|null
     */
    public static function for(string $path): ?array
    {
        $path = trim($path, '/');
        if (! in_array($path, ['', 'order'], true)) {
            return null;
        }
        $description = self::shorten(SiteContent::summary());

        return [
            'title' => $path === '' ? 'Sudaya Coffee' : 'Menu & pesan · Sudaya Coffee',
            'description' => $description,
            'url' => url($path === '' ? '/' : '/order'),
            'image' => url(self::IMAGE),
            'canonical' => $path === '' ? url('/') : null,
        ];
    }

    /** Potong di batas kata, maksimal 160 karakter (batas tampilan mesin pencari). */
    public static function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= self::MAX_DESCRIPTION) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_DESCRIPTION - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space === false ? null : $space), ' ,.;:').'…';
    }
}
