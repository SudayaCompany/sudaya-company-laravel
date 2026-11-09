<?php

namespace App\Services\Attendance;

/**
 * Geofence absensi (PRD §13), port dari referensi Next.js `lib/attendance/geo.ts`.
 * Browser hanya mengirim koordinat + akurasi mentah; jarak dan keputusan selalu dihitung
 * ulang di server.
 *
 * Keterbatasan yang didokumentasikan (bukan diklaim teratasi): koordinat dari browser
 * bisa dipalsukan (mock location, emulator, DevTools). Geofence adalah satu sinyal;
 * foto selfie menjadi bukti pendamping.
 */
final class Geofence
{
    /** Radius rata-rata bumi (IUGG), meter. */
    private const EARTH_RADIUS_METER = 6_371_008.8;

    /** Fix GPS lebih tua dari ini dianggap cache, bukan posisi saat ini. */
    public const MAX_POSITION_AGE_MS = 120_000;

    /** Jarak great-circle (Haversine), meter. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METER * asin(min(1.0, sqrt($h)));
    }

    /**
     * Urutan keputusan:
     * 1. posisi harus segar (bukan cache lama);
     * 2. akurasi ≤ batas — tanpa itu jarak tidak bermakna;
     * 3. tugas luar disetujui & aktif → radius outlet tidak berlaku (GPS tetap dicatat);
     * 4. selain itu outlet wajib punya koordinat dan jarak ≤ radius outlet.
     * Radius tidak pernah dilonggarkan diam-diam; yang gagal diarahkan ke koreksi absen.
     *
     * @param  array{latitude: float, longitude: float, accuracy: float, ageMs: int}  $position
     * @param  array{latitude: float|null, longitude: float|null, radius: int}  $outlet
     * @return array{ok: bool, reason: string|null, message: string|null, distance: float|null, mode: string|null}
     */
    public static function evaluate(array $position, array $outlet, int $maxAccuracy, bool $outsideDuty): array
    {
        $hasOutlet = $outlet['latitude'] !== null && $outlet['longitude'] !== null;
        $distance = $hasOutlet
            ? self::distance($position['latitude'], $position['longitude'], (float) $outlet['latitude'], (float) $outlet['longitude'])
            : null;
        $fail = fn (string $reason, string $message) => ['ok' => false, 'reason' => $reason, 'message' => $message, 'distance' => $distance, 'mode' => null];

        if ($position['ageMs'] > self::MAX_POSITION_AGE_MS) {
            return $fail('stale_position', 'Lokasi GPS sudah lama (cache). Muat ulang lokasi lalu coba lagi.');
        }
        if ($position['accuracy'] > $maxAccuracy) {
            return $fail('accuracy_too_low', sprintf(
                'Akurasi GPS ±%d m melebihi batas ±%d m. Pindah ke dekat pintu atau jendela dan aktifkan lokasi presisi, atau ajukan koreksi absen.',
                (int) round($position['accuracy']),
                $maxAccuracy,
            ));
        }
        if ($outsideDuty) {
            return ['ok' => true, 'reason' => null, 'message' => null, 'distance' => $distance, 'mode' => 'outside_duty'];
        }
        if (! $hasOutlet) {
            return $fail('no_outlet_coordinates', 'Lokasi outlet untuk absen belum diatur. Hubungi admin.');
        }
        if ($distance > $outlet['radius']) {
            return $fail('outside_radius', sprintf(
                'Anda di luar area absen (%d m dari outlet, batas %d m).',
                (int) round($distance),
                $outlet['radius'],
            ));
        }

        return ['ok' => true, 'reason' => null, 'message' => null, 'distance' => $distance, 'mode' => 'outlet'];
    }
}
