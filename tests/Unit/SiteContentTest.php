<?php

namespace Tests\Unit;

use App\Exceptions\AccessException;
use App\Services\Site\SiteContent;
use PHPUnit\Framework\TestCase;

/** Konten halaman publik (Admin → Konten situs): validasi & normalisasi tanpa database. */
class SiteContentTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return [
            'about' => "Paragraf satu.\n\nParagraf dua.",
            'programs' => [['title' => 'Pelatihan Kopi', 'body' => 'Kelas kopi.'], ['title' => 'Pemberdayaan Petani Kopi', 'body' => 'Pendampingan petani.']],
            'donationUrl' => 'https://sahabatberbagi.org/program/petani-kopi',
            'whatsapp' => '0812-3456-7890',
            'instagram' => 'https://www.instagram.com/sudaya.coffee/',
            'email' => 'Halo@Sudaya.co',
            'trainings' => [['title' => 'Kelas Dasar Barista', 'schedule' => 'Sabtu, 09.00–12.00', 'location' => '', 'description' => null, 'url' => '']],
            ...$overrides,
        ];
    }

    private function rejects(array $raw, string $pattern): void
    {
        try {
            SiteContent::validate($raw);
            $this->fail('diharapkan ditolak');
        } catch (AccessException $error) {
            $this->assertSame('invalid', $error->reason);
            $this->assertMatchesRegularExpression($pattern, $error->getMessage());
        }
    }

    public function test_normalisasi_kontak_dan_pelatihan(): void
    {
        $content = SiteContent::validate($this->valid());

        $this->assertSame("Paragraf satu.\n\nParagraf dua.", $content['about']);
        $this->assertSame(['6281234567890', 'sudaya.coffee', 'halo@sudaya.co'], [$content['whatsapp'], $content['instagram'], $content['email']]);
        $this->assertSame([['title' => 'Kelas Dasar Barista', 'schedule' => 'Sabtu, 09.00–12.00', 'location' => null, 'description' => null, 'url' => null]], $content['trainings']);
        $this->assertSame('6281234567890', SiteContent::whatsapp('+62 812 3456 7890'));
        $this->assertSame('6281234567890', SiteContent::whatsapp('81234567890'));
        $this->assertSame('sudayacoffee', SiteContent::instagram('@sudayacoffee'));
    }

    public function test_kontak_kosong_boleh_dan_tautan_wajib_https(): void
    {
        $content = SiteContent::validate($this->valid(['whatsapp' => '', 'instagram' => null, 'email' => '  ', 'donationUrl' => '']));
        $this->assertSame([null, null, null, null], [$content['whatsapp'], $content['instagram'], $content['email'], $content['donationUrl']]);

        $this->rejects($this->valid(['donationUrl' => 'http://sahabatberbagi.org']), '/Tautan sedekah harus diawali https/');
        $this->rejects($this->valid(['donationUrl' => 'javascript:alert(1)']), '/Tautan sedekah harus diawali https/');
        $this->rejects($this->valid(['trainings' => [['title' => 'Kelas', 'url' => 'ftp://x']]]), '/Tautan daftar pelatihan 1 harus diawali https/');
        $this->rejects($this->valid(['whatsapp' => '12345']), '/Nomor WhatsApp tidak valid/');
        $this->rejects($this->valid(['whatsapp' => '0812abc']), '/hanya berisi angka/');
        $this->rejects($this->valid(['instagram' => '@nama dengan spasi']), '/Instagram tidak valid/');
        $this->rejects($this->valid(['email' => 'bukan-email']), '/Email tidak valid/');
    }

    public function test_wajib_dan_batas_panjang(): void
    {
        $this->rejects($this->valid(['about' => '']), '/Tentang Sudaya wajib diisi/');
        $this->rejects($this->valid(['programs' => [['title' => 'Satu', 'body' => 'x']]]), '/Isi kedua program/');
        $this->rejects($this->valid(['programs' => [['title' => '', 'body' => 'x'], ['title' => 'Dua', 'body' => 'y']]]), '/Judul program 1 wajib diisi/');
        $this->rejects($this->valid(['trainings' => [['title' => '']]]), '/Judul pelatihan 1 wajib diisi/');
        $this->rejects($this->valid(['trainings' => array_fill(0, SiteContent::MAX_TRAININGS + 1, ['title' => 'Kelas'])]), '/Maksimal 12 info pelatihan/');
        $this->rejects($this->valid(['about' => str_repeat('a', 1201)]), '/maksimal 1200 karakter/');
    }

    public function test_konten_tersimpan_digabung_dengan_bawaan(): void
    {
        $merged = SiteContent::merge(['about' => 'Teks owner', 'tidakDikenal' => 'x', 'programs' => 'rusak']);

        $this->assertSame('Teks owner', $merged['about']);
        $this->assertArrayNotHasKey('tidakDikenal', $merged);
        $this->assertSame(SiteContent::DEFAULTS['programs'], $merged['programs']);
        $this->assertSame('https://sahabatberbagi.org', $merged['donationUrl']);
        $this->assertSame([null, null, null], [$merged['whatsapp'], $merged['instagram'], $merged['email']]);
    }
}
