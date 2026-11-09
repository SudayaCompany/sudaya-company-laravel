<?php

namespace Tests\Unit;

use App\Services\Attendance\Geofence;
use PHPUnit\Framework\TestCase;

class GeofenceTest extends TestCase
{
    /** Titik acuan di Bandung; 0,0001° lintang ≈ 11,1 m. */
    private const OUTLET = ['latitude' => -6.914744, 'longitude' => 107.609810, 'radius' => 30];

    private function at(float $dLat, float $accuracy = 10, int $ageMs = 1_000): array
    {
        return ['latitude' => self::OUTLET['latitude'] + $dLat, 'longitude' => self::OUTLET['longitude'], 'accuracy' => $accuracy, 'ageMs' => $ageMs];
    }

    public function test_jarak_haversine_mendekati_nilai_sebenarnya(): void
    {
        $this->assertEqualsWithDelta(11.12, Geofence::distance(0, 0, 0.0001, 0), 0.05);
        // Monas → Gedung Sate: aproksimasi equirectangular √(80,85² + 87,45²) ≈ 119,1 km.
        $this->assertEqualsWithDelta(119_100, Geofence::distance(-6.175392, 106.827153, -6.902476, 107.618784), 300);
        $this->assertSame(0.0, Geofence::distance(-6.9, 107.6, -6.9, 107.6));
    }

    public function test_di_dalam_radius_diterima(): void
    {
        $result = Geofence::evaluate($this->at(0.0002), self::OUTLET, 30, false);
        $this->assertTrue($result['ok']);
        $this->assertSame('outlet', $result['mode']);
        $this->assertEqualsWithDelta(22.2, $result['distance'], 0.2);
    }

    public function test_di_luar_radius_ditolak_dengan_jarak(): void
    {
        $result = Geofence::evaluate($this->at(0.0005), self::OUTLET, 30, false);
        $this->assertFalse($result['ok']);
        $this->assertSame('outside_radius', $result['reason']);
        $this->assertSame('Anda di luar area absen (56 m dari outlet, batas 30 m).', $result['message']);
    }

    public function test_akurasi_buruk_dan_posisi_lama_ditolak_lebih_dulu(): void
    {
        $this->assertSame('accuracy_too_low', Geofence::evaluate($this->at(0, accuracy: 31), self::OUTLET, 30, false)['reason']);
        $this->assertTrue(Geofence::evaluate($this->at(0, accuracy: 30), self::OUTLET, 30, false)['ok'], 'akurasi tepat di batas diterima');
        $this->assertSame('stale_position', Geofence::evaluate($this->at(0, ageMs: 120_001), self::OUTLET, 30, false)['reason']);
        // Tugas luar tidak melonggarkan syarat akurasi.
        $this->assertSame('accuracy_too_low', Geofence::evaluate($this->at(1, accuracy: 80), self::OUTLET, 30, true)['reason']);
    }

    public function test_tugas_luar_mengabaikan_radius_dan_outlet_tanpa_koordinat_ditolak(): void
    {
        $far = Geofence::evaluate($this->at(0.5), self::OUTLET, 30, true);
        $this->assertTrue($far['ok']);
        $this->assertSame('outside_duty', $far['mode']);

        $none = Geofence::evaluate($this->at(0), ['latitude' => null, 'longitude' => null, 'radius' => 30], 30, false);
        $this->assertSame('no_outlet_coordinates', $none['reason']);
        $this->assertNull($none['distance']);
    }
}
