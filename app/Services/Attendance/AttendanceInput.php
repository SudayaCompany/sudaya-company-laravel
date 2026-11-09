<?php

namespace App\Services\Attendance;

use App\Exceptions\AccessException;
use App\Services\Catalog\CatalogInput;
use App\Support\Money;

/** Validasi input absensi dan karyawan. Semua nilai dari browser dianggap tidak tepercaya. */
final class AttendanceInput
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /** Waktu lokal dari <input type="datetime-local">, zona waktu outlet. */
    private const LOCAL_DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/';

    /**
     * Posisi dari Geolocation API browser (form multipart: angka dikirim sebagai teks).
     *
     * @return array{latitude: float, longitude: float, accuracy: float, ageMs: int}
     */
    public static function position(array $raw): array
    {
        $number = fn (string $key) => is_numeric($raw[$key] ?? null) ? (float) $raw[$key] : null;
        [$lat, $lng, $accuracy, $age] = [$number('latitude'), $number('longitude'), $number('accuracy'), $number('ageMs')];
        if ($lat === null || $lng === null || $accuracy === null || $age === null) {
            self::fail('Lokasi GPS wajib dikirim. Aktifkan GPS lalu coba lagi.');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ! is_finite($lat) || ! is_finite($lng)) {
            self::fail('Lokasi tidak valid.');
        }
        if ($accuracy <= 0 || $accuracy > 100_000 || $age < 0 || $age > 86_400_000) {
            self::fail('Akurasi GPS tidak valid.');
        }

        return ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy, 'ageMs' => (int) $age];
    }

    /** @return array{latitude: float|null, longitude: float|null, radius: int} */
    public static function geofence(array $raw): array
    {
        $blank = fn ($v) => $v === null || (is_string($v) && trim($v) === '');
        $lat = $raw['latitude'] ?? null;
        $lng = $raw['longitude'] ?? null;
        if ($blank($lat) !== $blank($lng)) {
            self::fail('Latitude dan longitude harus diisi berpasangan.');
        }
        if (! $blank($lat) && (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
            self::fail('Koordinat tidak valid. Contoh: -6.914744 dan 107.609810.');
        }
        $radius = filter_var($raw['radius'] ?? null, FILTER_VALIDATE_INT);
        if ($radius === false || $radius < 5 || $radius > 1000) {
            self::fail('Radius absen harus 5–1.000 meter.');
        }

        return [
            'latitude' => $blank($lat) ? null : round((float) $lat, 7),
            'longitude' => $blank($lng) ? null : round((float) $lng, 7),
            'radius' => $radius,
        ];
    }

    public static function maxAccuracy(mixed $value): int
    {
        $accuracy = filter_var($value, FILTER_VALIDATE_INT);
        if ($accuracy === false || $accuracy < 5 || $accuracy > 1000) {
            self::fail('Batas akurasi GPS harus 5–1.000 meter.');
        }

        return $accuracy;
    }

    /**
     * @return array{employeeCode?: string, fullName: string, phone: string|null, position: string|null, outletId: string, joinedAt: string|null, userId: string|null, isActive?: bool}
     */
    public static function employee(array $raw, bool $create): array
    {
        $text = fn ($v) => is_string($v) ? trim($v) : '';
        $fullName = $text($raw['fullName'] ?? null);
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 80) {
            self::fail('Nama karyawan 2–80 karakter.');
        }
        $phone = $text($raw['phone'] ?? null);
        if ($phone !== '' && ! preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
            self::fail('Nomor telepon tidak valid.');
        }
        $position = $text($raw['position'] ?? null);
        if (mb_strlen($position) > 60) {
            self::fail('Jabatan maksimal 60 karakter.');
        }
        $joinedAt = $text($raw['joinedAt'] ?? null);
        if ($joinedAt !== '' && (! preg_match(self::DATE, $joinedAt) || ! checkdate((int) substr($joinedAt, 5, 2), (int) substr($joinedAt, 8, 2), (int) substr($joinedAt, 0, 4)))) {
            self::fail('Tanggal bergabung tidak valid.');
        }
        if (! CatalogInput::isUuid($raw['outletId'] ?? null)) {
            self::fail('Pilih outlet.');
        }
        $userId = $raw['userId'] ?? null;
        if ($userId !== null && $userId !== '' && ! CatalogInput::isUuid($userId)) {
            self::fail('Akun tidak valid.');
        }

        $input = [
            'fullName' => $fullName,
            'phone' => $phone === '' ? null : $phone,
            'position' => $position === '' ? null : $position,
            'outletId' => strtolower($raw['outletId']),
            'joinedAt' => $joinedAt === '' ? null : $joinedAt,
            'userId' => $userId === null || $userId === '' ? null : strtolower($userId),
        ];
        if ($create) {
            $code = strtoupper($text($raw['employeeCode'] ?? null));
            if (! preg_match('/^[A-Z0-9._-]{2,20}$/', $code)) {
                self::fail('Kode karyawan 2–20 karakter: huruf, angka, titik, minus, atau garis bawah.');
            }
            $input['employeeCode'] = $code;
        } else {
            $input['isActive'] = CatalogInput::boolean($raw['isActive'] ?? true);
        }

        return $input;
    }

    /**
     * Pengajuan koreksi oleh staf. Jam dalam waktu lokal outlet ("YYYY-MM-DDTHH:MM").
     *
     * @return array{kind: string, attendanceId: string|null, checkIn: string|null, checkOut: string, reason: string}
     */
    public static function correction(array $raw): array
    {
        $kind = $raw['kind'] ?? null;
        if (! in_array($kind, ['missing_attendance', 'missing_check_out'], true)) {
            self::fail('Pilih jenis koreksi.');
        }
        $local = function ($value, string $label) {
            if (! is_string($value) || ! preg_match(self::LOCAL_DATETIME, $value)) {
                self::fail("{$label} wajib diisi.");
            }

            return $value;
        };
        $checkOut = $local($raw['checkOut'] ?? null, 'Jam pulang');
        $checkIn = $kind === 'missing_attendance' ? $local($raw['checkIn'] ?? null, 'Jam masuk') : null;
        $attendanceId = $kind === 'missing_check_out' ? CatalogInput::uuid($raw['attendanceId'] ?? null) : null;

        return [
            'kind' => $kind,
            'attendanceId' => $attendanceId,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'reason' => Money::reason($raw['reason'] ?? null, 300, 'Alasan wajib diisi.', 'Alasan minimal 3 karakter.'),
        ];
    }

    public static function month(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }

    public static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::DATE, $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    private static function fail(string $message): never
    {
        throw new AccessException('invalid', $message);
    }
}
