<?php

namespace Tests\Unit;

use App\Exceptions\AccessException;
use App\Services\Outlets\OutletInput;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Validasi pengaturan outlet dan daftar meja (tanpa database). */
class OutletInputTest extends TestCase
{
    private static function valid(array $overrides = []): array
    {
        return ['name' => 'Sudaya Coffee Kemang', 'address' => 'Jl. Kemang Raya 1', 'orderNumberPrefix' => 'skm', 'pendingPaymentTtlMinutes' => '20', 'isActive' => true, ...$overrides];
    }

    private static function error(callable $call): ?string
    {
        try {
            $call();

            return null;
        } catch (AccessException $e) {
            return $e->getMessage();
        }
    }

    public function test_settings_dinormalkan(): void
    {
        $this->assertSame(
            ['name' => 'Sudaya Coffee Kemang', 'address' => 'Jl. Kemang Raya 1', 'orderNumberPrefix' => 'SKM', 'pendingPaymentTtlMinutes' => 20, 'isActive' => true],
            OutletInput::settings(self::valid()),
        );
        $this->assertNull(OutletInput::settings(self::valid(['address' => '   ']))['address']);
        $this->assertSame(180, OutletInput::settings(self::valid(['pendingPaymentTtlMinutes' => 180]))['pendingPaymentTtlMinutes']);
    }

    public static function invalidSettings(): array
    {
        return [
            'nama pendek' => [['name' => 'A'], 'Nama outlet minimal 2 karakter.'],
            'nama panjang' => [['name' => str_repeat('a', 81)], 'Nama outlet maksimal 80 karakter.'],
            'alamat panjang' => [['address' => str_repeat('a', 201)], 'Alamat maksimal 200 karakter.'],
            'prefix kosong' => [['orderNumberPrefix' => ''], 'Prefix antrean 1–6 huruf atau angka, mis. SDY.'],
            'prefix simbol' => [['orderNumberPrefix' => 'SD-Y'], 'Prefix antrean 1–6 huruf atau angka, mis. SDY.'],
            'prefix 7 karakter' => [['orderNumberPrefix' => 'ABCDEFG'], 'Prefix antrean 1–6 huruf atau angka, mis. SDY.'],
            'ttl 4' => [['pendingPaymentTtlMinutes' => 4], 'Batas waktu pembayaran harus 5–180 menit.'],
            'ttl 181' => [['pendingPaymentTtlMinutes' => 181], 'Batas waktu pembayaran harus 5–180 menit.'],
            'ttl pecahan' => [['pendingPaymentTtlMinutes' => '15.5'], 'Batas waktu pembayaran harus 5–180 menit.'],
            'status bukan boolean' => [['isActive' => 'yes'], 'Input tidak valid.'],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_settings_ditolak(array $overrides, string $message): void
    {
        $this->assertSame($message, self::error(fn () => OutletInput::settings(self::valid($overrides))));
    }

    public function test_daftar_meja_dipisah_dinormalkan_dan_tanpa_duplikat(): void
    {
        $this->assertSame(['T01', 'T02', 'VIP-1', 'T10'], OutletInput::tableNumbers(" t01, T02\nvip-1;t01  T10 "));
    }

    public function test_daftar_meja_ditolak(): void
    {
        $this->assertSame('Isi nomor meja.', self::error(fn () => OutletInput::tableNumbers(' , ')));
        $this->assertSame('Isi nomor meja.', self::error(fn () => OutletInput::tableNumbers(null)));
        $this->assertStringContainsString('"MEJA#1" tidak valid', self::error(fn () => OutletInput::tableNumbers('MEJA#1')));
        $this->assertStringContainsString('"ABCDEFGHIJK" tidak valid', self::error(fn () => OutletInput::tableNumbers('ABCDEFGHIJK')));
        $this->assertSame('Maksimal 50 meja sekali tambah.', self::error(fn () => OutletInput::tableNumbers(implode(',', array_map(fn ($i) => "T{$i}", range(1, 51))))));
    }
}
