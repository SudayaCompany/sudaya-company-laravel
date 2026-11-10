<?php

namespace Tests\Unit;

use App\Exceptions\AccessException;
use App\Services\Inventory\Qty;
use PHPUnit\Framework\TestCase;

class QtyTest extends TestCase
{
    private function rejects(callable $call, string $pattern): void
    {
        try {
            $call();
            $this->fail('diharapkan ditolak');
        } catch (AccessException $error) {
            $this->assertSame('invalid', $error->reason);
            $this->assertMatchesRegularExpression($pattern, $error->getMessage());
        }
    }

    public function test_parse_konversi_tepat_tanpa_float(): void
    {
        $this->assertSame(1_500_000, Qty::parse('1,5', 'kg', 'g'), '1,5 kg = 1500 g');
        $this->assertSame(1_500, Qty::parse('1500', 'g', 'kg'), '1500 g = 1,5 kg');
        $this->assertSame(250, Qty::parse('250', 'ml', 'l'));
        $this->assertSame(3_000, Qty::parse('3', 'pcs', 'pcs'));
        $this->assertSame(123, Qty::parse('0.123', 'kg', 'kg'));
        $this->assertSame(1, Qty::parse('1', 'g', 'kg'), '1 g = 0,001 kg masih tepat');
    }

    public function test_parse_menolak_input_tidak_valid(): void
    {
        $this->rejects(fn () => Qty::parse('1,5', 'pcs', 'pcs'), '/bilangan bulat/');
        $this->rejects(fn () => Qty::parse('0,5', 'g', 'kg'), '/terlalu kecil/');
        $this->rejects(fn () => Qty::parse('0', 'kg', 'kg'), '/lebih dari 0/');
        $this->rejects(fn () => Qty::parse('-2', 'kg', 'kg'), '/tidak valid/');
        $this->rejects(fn () => Qty::parse('1.2345', 'kg', 'kg'), '/3 angka/');
        $this->rejects(fn () => Qty::parse('2', 'pcs', 'kg'), '/Satuan tidak sesuai/');
        $this->rejects(fn () => Qty::parse('abc', 'kg', 'kg'), '/tidak valid/');
    }

    public function test_db_dan_format(): void
    {
        $this->assertSame(12_500, Qty::fromDb('12.500'));
        $this->assertSame(-2_000, Qty::fromDb('-2.000'));
        $this->assertSame(0, Qty::fromDb(null));
        $this->assertSame('-2.000', Qty::toDb(-2_000));
        $this->assertSame('0.005', Qty::toDb(5));
        $this->assertSame('12,5 kg', Qty::format(12_500, 'kg'));
        $this->assertSame('1.250 g', Qty::format(1_250_000, 'g'));
        $this->assertSame('3 pcs', Qty::format(3_000, 'pcs'));
    }

    public function test_harga_per_satuan_dasar(): void
    {
        $this->assertSame('200.0000', Qty::unitCost('200000', 'kg', 'g'), 'Rp200.000/kg = Rp200/g');
        $this->assertSame('15000.0000', Qty::unitCost('15000', 'pcs', 'pcs'));
        $this->assertNull(Qty::unitCost('', 'kg', 'g'));
        $this->rejects(fn () => Qty::unitCost('12,5', 'kg', 'g'), '/rupiah bulat/');
    }
}
