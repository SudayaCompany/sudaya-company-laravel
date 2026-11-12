<?php

namespace App\Services\Site;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Seed\InitialData;
use App\Support\Access\AccessScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Konten halaman publik per brand (permintaan owner 11 November 2026): tentang Sudaya, dua program
 * (pelatihan kopi, pemberdayaan petani kopi), info pelatihan, tautan sedekah ke Sahabat Berbagi,
 * dan kontak footer. Disimpan di brands.site_content; diubah Super Admin (settings.manage).
 *
 * Teks bawaan sengaja umum: tidak ada angka, tanggal, atau klaim yang belum diberikan owner.
 * Kontak kosong sampai diisi di Admin → Konten situs.
 */
final class SiteContent
{
    public const MAX_TRAININGS = 12;

    public const DEFAULTS = [
        'about' => 'Sudaya Coffee adalah kedai kopi yang tumbuh bersama petani kopi. Selain menyajikan kopi, Sudaya menjalankan program pelatihan kopi dan pemberdayaan petani kopi, serta mengajak siapa pun ikut berbagi untuk petani lewat Sahabat Berbagi.',
        'programs' => [
            ['title' => 'Pelatihan Kopi', 'body' => 'Kelas seputar kopi untuk umum dan calon barista: mengenal biji kopi, teknik seduh, hingga dasar pelayanan di kedai. Jadwal terbaru ada di Info pelatihan.'],
            ['title' => 'Pemberdayaan Petani Kopi', 'body' => 'Pendampingan petani kopi agar panen dan pengolahan kopinya makin baik dan bernilai, sehingga kopi yang Anda nikmati ikut menyejahterakan petaninya.'],
        ],
        'donationUrl' => 'https://sahabatberbagi.org',
        'whatsapp' => null,
        'instagram' => null,
        'email' => null,
        'trainings' => [],
    ];

    /** Ringkasan "tentang" untuk meta deskripsi/pratinjau tautan; dibersihkan saat konten diubah. */
    public const SUMMARY_CACHE_KEY = 'site:summary';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Teks "tentang" brand utama untuk meta halaman publik. Di-cache 10 menit; bila database
     * tidak terjangkau, teks bawaan dipakai agar halaman tetap tampil.
     */
    public static function summary(): string
    {
        try {
            return (string) Cache::remember(self::SUMMARY_CACHE_KEY, 600, function () {
                $raw = DB::table('brands')->join('companies', 'companies.id', '=', 'brands.company_id')
                    ->where('companies.code', InitialData::TARGET['company']['code'])
                    ->orderBy('brands.created_at')->value('brands.site_content');
                $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

                return (string) self::merge($stored)['about'];
            });
        } catch (Throwable) {
            return self::DEFAULTS['about'];
        }
    }

    /** Konten untuk halaman publik (bawaan + isian admin) beserta tautan kontak siap pakai. */
    public static function forPublic(?string $brandId): array
    {
        $stored = [];
        if ($brandId !== null) {
            try {
                $raw = DB::table('brands')->where('id', $brandId)->value('site_content');
                $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            } catch (Throwable) {
                $stored = [];
            }
        }
        $content = self::merge($stored);

        return [
            ...$content,
            'links' => [
                'whatsapp' => $content['whatsapp'] === null ? null : 'https://wa.me/'.$content['whatsapp'],
                'instagram' => $content['instagram'] === null ? null : 'https://instagram.com/'.$content['instagram'],
                'email' => $content['email'] === null ? null : 'mailto:'.$content['email'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function merge(array $stored): array
    {
        $content = [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
        if (! is_array($content['programs']) || count($content['programs']) !== 2) {
            $content['programs'] = self::DEFAULTS['programs'];
        }
        if (! is_array($content['trainings'])) {
            $content['trainings'] = [];
        }

        return $content;
    }

    /** Untuk form admin: brand milik Super Admin + isian saat ini. */
    public function forAdmin(User $actor): array
    {
        $brand = $this->brand($actor);
        $raw = $brand->site_content;

        return ['brand' => ['id' => $brand->id, 'name' => $brand->name], 'content' => self::merge(is_string($raw) ? (json_decode($raw, true) ?: []) : [])];
    }

    public function update(User $actor, array $raw, AuditContext $context): array
    {
        $brand = $this->brand($actor);
        $content = self::validate($raw);
        $before = self::merge(is_string($brand->site_content) ? (json_decode($brand->site_content, true) ?: []) : []);
        DB::transaction(function () use ($actor, $brand, $content, $before, $context) {
            DB::table('brands')->where('id', $brand->id)->update(['site_content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => DB::raw('now()')]);
            $changed = array_values(array_filter(array_keys($content), fn ($key) => $content[$key] !== $before[$key]));
            $this->audit->record([
                'company_id' => $actor->company_id,
                'brand_id' => $brand->id,
                'actor_id' => $actor->id,
                'action' => 'site.content_updated',
                'entity_type' => 'brand',
                'entity_id' => $brand->id,
                'after' => ['changed' => $changed, 'trainings' => count($content['trainings'])],
            ], $context);
        });
        Cache::forget(self::SUMMARY_CACHE_KEY);

        return $content;
    }

    /**
     * Validasi & normalisasi isian admin. Kontak opsional; tautan wajib https.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $raw): array
    {
        $programs = $raw['programs'] ?? null;
        if (! is_array($programs) || count($programs) !== 2) {
            self::fail('Isi kedua program.');
        }
        $trainings = $raw['trainings'] ?? [];
        if (! is_array($trainings)) {
            self::fail('Data pelatihan tidak valid.');
        }
        if (count($trainings) > self::MAX_TRAININGS) {
            self::fail('Maksimal '.self::MAX_TRAININGS.' info pelatihan.');
        }

        return [
            'about' => self::text($raw['about'] ?? null, 'Tentang Sudaya', 1200, required: true),
            'programs' => array_map(fn ($p, $i) => [
                'title' => self::text(is_array($p) ? ($p['title'] ?? null) : null, 'Judul program '.($i + 1), 60, required: true),
                'body' => self::text(is_array($p) ? ($p['body'] ?? null) : null, 'Penjelasan program '.($i + 1), 500, required: true),
            ], array_values($programs), [0, 1]),
            'donationUrl' => self::url($raw['donationUrl'] ?? null, 'Tautan sedekah'),
            'whatsapp' => self::whatsapp($raw['whatsapp'] ?? null),
            'instagram' => self::instagram($raw['instagram'] ?? null),
            'email' => self::email($raw['email'] ?? null),
            'trainings' => array_map(fn ($t, $i) => [
                'title' => self::text(is_array($t) ? ($t['title'] ?? null) : null, 'Judul pelatihan '.($i + 1), 100, required: true),
                'schedule' => self::text(is_array($t) ? ($t['schedule'] ?? null) : null, 'Jadwal pelatihan '.($i + 1), 100),
                'location' => self::text(is_array($t) ? ($t['location'] ?? null) : null, 'Lokasi pelatihan '.($i + 1), 120),
                'description' => self::text(is_array($t) ? ($t['description'] ?? null) : null, 'Keterangan pelatihan '.($i + 1), 500),
                'url' => self::url(is_array($t) ? ($t['url'] ?? null) : null, 'Tautan daftar pelatihan '.($i + 1)),
            ], array_values($trainings), array_keys(array_values($trainings))),
        ];
    }

    /** Nomor WhatsApp → format internasional tanpa +, mis. 0812-3456-789 → 628123456789. */
    public static function whatsapp(mixed $value): ?string
    {
        $value = self::text($value, 'Nomor WhatsApp', 30);
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/[\s\-().+]/', '', $value);
        if (! preg_match('/^\d+$/', $digits)) {
            self::fail('Nomor WhatsApp hanya berisi angka, mis. 0812 3456 7890.');
        }
        $digits = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : (str_starts_with($digits, '8') ? '62'.$digits : $digits);
        if (! preg_match('/^62\d{8,13}$/', $digits)) {
            self::fail('Nomor WhatsApp tidak valid, mis. 0812 3456 7890.');
        }

        return $digits;
    }

    /** Akun Instagram: @nama, nama, atau tautan instagram.com/nama → nama. */
    public static function instagram(mixed $value): ?string
    {
        $value = self::text($value, 'Instagram', 120);
        if ($value === null) {
            return null;
        }
        $handle = preg_replace('#^(https?://)?(www\.)?instagram\.com/#i', '', $value);
        $handle = trim(ltrim($handle, '@'), '/');
        if (! preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle)) {
            self::fail('Akun Instagram tidak valid, mis. @sudayacoffee.');
        }

        return $handle;
    }

    public static function email(mixed $value): ?string
    {
        $value = self::text($value, 'Email', 120);
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            self::fail('Email tidak valid.');
        }

        return strtolower($value);
    }

    public static function url(mixed $value, string $label): ?string
    {
        $value = self::text($value, $label, 300);
        if ($value === null) {
            return null;
        }
        $parts = parse_url($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            self::fail("{$label} harus diawali https://.");
        }

        return $value;
    }

    private static function text(mixed $value, string $label, int $max, bool $required = false): ?string
    {
        if ($value !== null && ! is_string($value)) {
            self::fail("{$label} tidak valid.");
        }
        $value = $value === null ? '' : trim(preg_replace('/[^\S\n]+/u', ' ', str_replace("\r\n", "\n", $value)));
        if ($value === '') {
            if ($required) {
                self::fail("{$label} wajib diisi.");
            }

            return null;
        }
        if (mb_strlen($value) > $max) {
            self::fail("{$label} maksimal {$max} karakter.");
        }

        return $value;
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }

    private function brand(User $actor): object
    {
        if (! $actor->can('settings.manage', AccessScope::company())) {
            throw AccessException::forbidden();
        }

        return DB::table('brands')->where('company_id', $actor->company_id)->orderBy('created_at')->first(['id', 'name', 'site_content'])
            ?? throw new AccessException('not_found', 'Brand tidak ditemukan.');
    }
}
