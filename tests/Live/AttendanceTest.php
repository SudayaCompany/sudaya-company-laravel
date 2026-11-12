<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Attendance\AttendanceAdmin;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendancePhotoStorage;
use App\Services\Attendance\Employees;
use App\Services\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * M7-lite: absensi GPS + selfie (keputusan owner 8–9 November), koreksi, papan & rekap,
 * data karyawan, pengaturan lokasi, retensi foto, izin, dan isolasi tenant.
 */
class AttendanceTest extends LiveTestCase
{
    /** Titik outlet uji; 0,0001° lintang ≈ 11,1 m. */
    private const LAT = -6.914744;

    private const LNG = 107.609810;

    /** Penyimpanan foto palsu bersama (di memori) untuk seluruh test di kelas ini. */
    public static array $stored = [];

    protected function fixtures(LiveContext $ctx): array
    {
        $A = $ctx->createCompany('atta');
        $B = $ctx->createCompany('attb');
        $users = [
            'adminA' => $ctx->createUser($A, [['role' => 'super_admin']]),
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'loneA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
            'cashierB' => $ctx->createUser($B, [['role' => 'cashier', 'outlet' => 'outlet']]),
        ];
        DB::table('outlets')->where('id', $A['outletId'])->update(['latitude' => self::LAT, 'longitude' => self::LNG, 'attendance_radius_meter' => 30]);
        DB::table('outlets')->where('id', $B['outletId'])->update(['latitude' => self::LAT, 'longitude' => self::LNG, 'attendance_radius_meter' => 30]);
        $employee = fn (array $company, string $user, string $code, string $name) => DB::table('employees')->insertGetId([
            'company_id' => $company['id'], 'brand_id' => $company['brandId'], 'outlet_id' => $company['outletId'],
            'user_id' => $users[$user]['id'], 'employee_code' => $code, 'full_name' => $name,
        ]);

        return ['A' => $A, 'B' => $B, ...$users,
            'empCashier' => $employee($A, 'cashierA', 'QA-KSR', 'Kasir Absen QA'),
            'empBarista' => $employee($A, 'baristaA', 'QA-BRS', 'Barista Absen QA'),
            'empB' => $employee($B, 'cashierB', 'QB-KSR', 'Kasir Tenant B'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AttendancePhotoStorage::class, new class implements AttendancePhotoStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void
            {
                AttendanceTest::$stored[$path] = $bytes;
            }

            public function download(string $path): ?string
            {
                return AttendanceTest::$stored[$path] ?? null;
            }

            public function remove(array $paths): void
            {
                foreach ($paths as $path) {
                    unset(AttendanceTest::$stored[$path]);
                }
            }
        });
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function engine(): AttendanceEngine
    {
        return app(AttendanceEngine::class);
    }

    /** Posisi uji: $dLat derajat lintang dari titik outlet. */
    private function at(float $dLat = 0.0001, float $accuracy = 10, int $ageMs = 1_000): array
    {
        return ['latitude' => (string) (self::LAT + $dLat), 'longitude' => (string) self::LNG, 'accuracy' => (string) $accuracy, 'ageMs' => (string) $ageMs];
    }

    private function selfie(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('selfie.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 2048));
    }

    private function expectAccess(string $reason, callable $call, ?string $pattern = null): void
    {
        try {
            $call();
            $this->fail("diharapkan AccessException {$reason}");
        } catch (AccessException $error) {
            $this->assertSame($reason, $error->reason, $error->getMessage());
            if ($pattern !== null) {
                $this->assertMatchesRegularExpression($pattern, $error->getMessage());
            }
        }
    }

    private function local(string $relative, string $company = 'A'): string
    {
        $tz = DB::table('outlets')->where('id', static::$fixture[$company]['outletId'])->value('timezone');

        return CarbonImmutable::now($tz)->modify($relative)->format('Y-m-d\TH:i');
    }

    public function test_absen_masuk_dan_pulang_dengan_gps_dan_selfie(): void
    {
        $A = static::$fixture['A'];
        $in = $this->engine()->checkIn($this->actor('cashierA'), $this->at(0.0001, 12), $this->selfie(), $this->audit());
        $row = DB::table('attendances')->where('id', $in['id'])->first();
        $this->assertSame([AttendanceEngine::METHOD, 'checked_in'], [$row->check_in_method, $row->status]);
        $this->assertSame(static::$fixture['empCashier'], $row->employee_id);
        $this->assertEqualsWithDelta(11.1, (float) $row->check_in_distance_meter, 0.2);
        $this->assertSame(12.0, (float) $row->check_in_accuracy_meter);
        $this->assertSame(AttendanceEngine::POLICY_VERSION, $row->policy_version);
        $this->assertStringStartsWith("selfie/{$A['id']}/".static::$fixture['empCashier'].'/', $row->check_in_photo_path);
        $this->assertArrayHasKey($row->check_in_photo_path, self::$stored);
        $this->assertSame('phpunit', $row->check_in_device);
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'attendance.check_in', $in['id']));

        $state = $this->engine()->state($this->actor('cashierA'));
        $this->assertSame($in['id'], $state['open']['id']);
        $this->assertFalse($state['open']['fromEarlierDay']);
        $this->assertTrue($state['employee']['geofenceReady']);
        $this->assertSame(['maxAccuracy' => 30, 'policyVersion' => AttendanceEngine::POLICY_VERSION], $state['settings']);

        $this->expectAccess('conflict', fn () => $this->engine()->checkIn($this->actor('cashierA'), $this->at(), $this->selfie(), $this->audit()), '/masih tercatat masuk/');

        // Jam kerja dihitung DB: mundurkan jam masuk 125 menit.
        DB::table('attendances')->where('id', $in['id'])->update(['check_in_at' => DB::raw("now() - interval '125 minutes'")]);
        $out = $this->engine()->checkOut($this->actor('cashierA'), $this->at(-0.0002, 8), $this->selfie(), $this->audit());
        $this->assertContains($out['workingMinutes'], [124, 125]);
        $row = DB::table('attendances')->where('id', $in['id'])->first();
        $this->assertSame(['checked_out', AttendanceEngine::METHOD], [$row->status, $row->check_out_method]);
        $this->assertNotNull($row->check_out_photo_path);
        $this->assertNotSame($row->check_in_photo_path, $row->check_out_photo_path);
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'attendance.check_out', $in['id']));
        $this->expectAccess('conflict', fn () => $this->engine()->checkOut($this->actor('cashierA'), $this->at(), $this->selfie(), $this->audit()), '/belum absen masuk/');
    }

    public function test_lokasi_dan_foto_tidak_valid_ditolak_tanpa_menyimpan(): void
    {
        $A = static::$fixture['A'];
        $before = [DB::table('attendances')->where('company_id', $A['id'])->count(), count(self::$stored)];
        $reject = fn (array $position, string $pattern) => $this->expectAccess('invalid', fn () => $this->engine()->checkIn($this->actor('cashierA'), $position, $this->selfie(), $this->audit()), $pattern);

        $reject($this->at(0.0005), '/^Anda di luar area absen \(56 m dari outlet, batas 30 m\)\.$/');
        $reject($this->at(0, 31), '/Akurasi GPS ±31 m melebihi batas ±30 m/');
        $reject($this->at(0, 10, 200_000), '/cache/');
        $reject(['latitude' => 'abc', 'longitude' => '1', 'accuracy' => '5', 'ageMs' => '0'], '/Lokasi GPS wajib dikirim/');
        $reject(['latitude' => '95', 'longitude' => '1', 'accuracy' => '5', 'ageMs' => '0'], '/Lokasi tidak valid/');
        $this->assertSame(3, $this->ctx()->countAudit($A['id'], 'attendance.location_rejected'));

        $this->expectAccess('invalid', fn () => $this->engine()->checkIn($this->actor('cashierA'), $this->at(), null, $this->audit()), '/Foto selfie wajib/');
        $fake = UploadedFile::fake()->createWithContent('selfie.jpg', '<?php echo 1;');
        $this->expectAccess('invalid', fn () => $this->engine()->checkIn($this->actor('cashierA'), $this->at(), $fake, $this->audit()), '/Format foto/');
        $this->assertSame($before, [DB::table('attendances')->where('company_id', $A['id'])->count(), count(self::$stored)]);

        // Akun tanpa data karyawan, dan pengaturan batas akurasi yang lebih longgar.
        $this->expectAccess('forbidden', fn () => $this->engine()->checkIn($this->actor('loneA'), $this->at(), $this->selfie(), $this->audit()), '/belum terhubung/');
        app(AttendanceAdmin::class)->updateAccuracy($this->actor('adminA'), '40', $this->audit());
        $this->assertSame(40, $this->engine()->settings($A['id'])['maxAccuracy']);
        $preview = $this->engine()->preview($this->actor('cashierA'), $this->at(0.0001, 35));
        $this->assertTrue($preview['ok']);
        $this->assertSame([35, 30, 40], [$preview['accuracy'], $preview['radius'], $preview['maxAccuracy']]);
        app(AttendanceAdmin::class)->updateAccuracy($this->actor('adminA'), '30', $this->audit());
        $this->expectAccess('forbidden', fn () => app(AttendanceAdmin::class)->updateAccuracy($this->actor('managerA'), '50', $this->audit()));
        $this->expectAccess('invalid', fn () => app(AttendanceAdmin::class)->updateAccuracy($this->actor('adminA'), '2', $this->audit()));
    }

    public function test_koreksi_lupa_pulang_dan_lupa_absen(): void
    {
        $A = static::$fixture['A'];
        $barista = $this->actor('baristaA');
        $session = $this->engine()->checkIn($barista, $this->at(), $this->selfie(), $this->audit())['id'];
        DB::table('attendances')->where('id', $session)->update(['check_in_at' => DB::raw("now() - interval '3 hours'")]);

        $this->expectAccess('invalid', fn () => $this->engine()->requestCorrection($barista, ['kind' => 'missing_check_out', 'attendanceId' => $session, 'checkOut' => $this->local('+2 hours'), 'reason' => 'lupa pulang'], $this->audit()), '/masa depan/');
        $this->expectAccess('invalid', fn () => $this->engine()->requestCorrection($barista, ['kind' => 'missing_check_out', 'attendanceId' => $session, 'checkOut' => $this->local('-5 hours'), 'reason' => 'lupa pulang'], $this->audit()), '/setelah jam masuk/');
        $this->expectAccess('invalid', fn () => $this->engine()->requestCorrection($barista, ['kind' => 'missing_check_out', 'attendanceId' => $session, 'checkOut' => $this->local('-1 hour'), 'reason' => 'ab'], $this->audit()), '/minimal 3/');

        $lupaPulang = $this->engine()->requestCorrection($barista, ['kind' => 'missing_check_out', 'attendanceId' => $session, 'checkOut' => $this->local('-1 hour'), 'reason' => 'HP mati saat pulang'], $this->audit());
        $this->expectAccess('conflict', fn () => $this->engine()->requestCorrection($barista, ['kind' => 'missing_check_out', 'attendanceId' => $session, 'checkOut' => $this->local('-1 hour'), 'reason' => 'HP mati saat pulang'], $this->audit()), '/masih menunggu/');

        $inbox = $this->engine()->pendingCorrections($this->actor('managerA'), ['id' => $A['outletId'], 'timezone' => 'Asia/Jakarta']);
        $this->assertCount(1, $inbox);
        $this->assertSame(['missing_check_out', 'Barista Absen QA', true], [$inbox[0]['kind'], $inbox[0]['employeeName'], $inbox[0]['canDecide']]);
        $this->expectAccess('forbidden', fn () => $this->engine()->pendingCorrections($this->actor('cashierA'), ['id' => $A['outletId'], 'timezone' => 'Asia/Jakarta']));
        $this->expectAccess('forbidden', fn () => $this->engine()->decideCorrection($this->actor('cashierA'), ['approvalId' => $lupaPulang['approvalId'], 'approve' => true], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->engine()->decideCorrection($this->actor('superB'), ['approvalId' => $lupaPulang['approvalId'], 'approve' => true], $this->audit()));

        $this->assertSame(['approved' => true, 'attendanceId' => $session], $this->engine()->decideCorrection($this->actor('managerA'), ['approvalId' => $lupaPulang['approvalId'], 'approve' => true], $this->audit()));
        $row = DB::table('attendances')->where('id', $session)->first();
        $this->assertSame(['checked_out', 'manual_correction', AttendanceEngine::METHOD], [$row->status, $row->check_out_method, $row->check_in_method]);
        $this->assertEqualsWithDelta(120, (int) $row->working_minutes, 1);
        $this->expectAccess('conflict', fn () => $this->engine()->decideCorrection($this->actor('ownerA'), ['approvalId' => $lupaPulang['approvalId'], 'approve' => false], $this->audit()), '/sudah diputuskan/');

        // Lupa absen sama sekali (kemarin 08.00–16.00), disetujui Owner.
        $yesterday = $this->local('-1 day');
        $date = substr($yesterday, 0, 10);
        $lupaAbsen = $this->engine()->requestCorrection($barista, ['kind' => 'missing_attendance', 'checkIn' => "{$date}T08:00", 'checkOut' => "{$date}T16:00", 'reason' => 'GPS rusak'], $this->audit());
        $result = $this->engine()->decideCorrection($this->actor('ownerA'), ['approvalId' => $lupaAbsen['approvalId'], 'approve' => true, 'note' => 'ok'], $this->audit());
        $made = DB::table('attendances')->where('id', $result['attendanceId'])->first();
        $this->assertSame([$date, 'manual_correction', 'manual_correction', 480], [$made->business_date, $made->check_in_method, $made->check_out_method, (int) $made->working_minutes]);
        $this->assertNull($made->check_in_photo_path);

        // Bertumpuk dengan sesi yang sudah ada → ditolak saat diputuskan; ditolak manager → tanpa sesi.
        $overlap = $this->engine()->requestCorrection($barista, ['kind' => 'missing_attendance', 'checkIn' => "{$date}T12:00", 'checkOut' => "{$date}T18:00", 'reason' => 'salah catat'], $this->audit());
        $this->expectAccess('conflict', fn () => $this->engine()->decideCorrection($this->actor('managerA'), ['approvalId' => $overlap['approvalId'], 'approve' => true], $this->audit()), '/bertumpuk/');
        $this->engine()->decideCorrection($this->actor('managerA'), ['approvalId' => $overlap['approvalId'], 'approve' => false, 'note' => 'sudah ada'], $this->audit());
        $this->assertSame('rejected', DB::table('approvals')->where('id', $overlap['approvalId'])->value('status'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'attendance.correction_rejected'));
        $this->assertSame(2, $this->ctx()->countAudit($A['id'], 'attendance.correction_approved'));

        $history = $this->engine()->history($barista, null);
        $this->assertGreaterThanOrEqual(1, count($history['rows']));
        $this->assertTrue(collect($history['rows'])->contains(fn ($r) => $r['manual']));
    }

    public function test_papan_rekap_ekspor_dan_foto_berizin(): void
    {
        $A = static::$fixture['A'];
        $outlet = ['id' => $A['outletId'], 'name' => 'Outlet Absen QA', 'timezone' => DB::table('outlets')->where('id', $A['outletId'])->value('timezone')];
        $admin = app(AttendanceAdmin::class);

        $board = $admin->board($this->actor('ownerA'), $outlet, null);
        $this->assertTrue($board['isToday']);
        $byName = collect($board['rows'])->keyBy('fullName');
        $this->assertSame('done', $byName['Kasir Absen QA']['status']);
        $this->assertTrue($byName['Kasir Absen QA']['sessions'][0]['hasCheckInPhoto']);
        $this->assertSame('done', $byName['Barista Absen QA']['status']);
        $this->assertSame(['working' => 0, 'done' => 2, 'absent' => 0], $board['counts']);
        $this->expectAccess('forbidden', fn () => $admin->board($this->actor('cashierA'), $outlet, null));

        $from = CarbonImmutable::now($outlet['timezone'])->subDays(3)->toDateString();
        $to = CarbonImmutable::now($outlet['timezone'])->toDateString();
        $report = collect($admin->report($this->actor('ownerA'), $outlet, $from, $to)['rows'])->keyBy('fullName');
        $this->assertSame(2, $report['Barista Absen QA']['days']);
        $this->assertSame(2, $report['Barista Absen QA']['manual']);
        // Jam kerja rekap = jumlah menit sesi di database (koreksi -1 jam dibulatkan ke menit: 119 + 480).
        $this->assertSame((int) DB::table('attendances')->where('employee_id', static::$fixture['empBarista'])->sum('working_minutes'), $report['Barista Absen QA']['minutes']);
        $this->assertContains($report['Barista Absen QA']['minutes'], [599, 600]);
        $this->assertSame(1, $report['Kasir Absen QA']['days']);
        $this->expectAccess('invalid', fn () => $admin->report($this->actor('ownerA'), $outlet, '2026-01-01', '2026-06-01'), '/62 hari/');
        $this->expectAccess('forbidden', fn () => $admin->report($this->actor('cashierA'), $outlet, $from, $to));

        $file = $admin->exportReport($this->actor('ownerA'), $outlet, $from, $to);
        $this->assertMatchesRegularExpression('/^absensi-outlet-absen-qa-\d{4}-\d{2}-\d{2}-sd-\d{4}-\d{2}-\d{2}\.xlsx$/', $file['filename']);
        $path = tempnam(sys_get_temp_dir(), 'attx');
        file_put_contents($path, $file['bytes']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('Barista Absen QA', $sheet);
        $this->assertStringContainsString('Jam kerja (menit)', $sheet);

        $sessionId = $byName['Kasir Absen QA']['sessions'][0]['id'];
        $photo = $admin->photo($this->actor('ownerA'), $sessionId, 'in');
        $this->assertSame('image/jpeg', $photo['contentType']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $photo['bytes']);
        $this->expectAccess('forbidden', fn () => $admin->photo($this->actor('cashierA'), $sessionId, 'in'));
        $this->expectAccess('not_found', fn () => $admin->photo($this->actor('superB'), $sessionId, 'in'));

        // Tenant lain tidak melihat karyawan atau absen company A.
        $boardB = $admin->board($this->actor('superB'), ['id' => static::$fixture['B']['outletId'], 'timezone' => $outlet['timezone']], null);
        $this->assertSame(['Kasir Tenant B'], array_column($boardB['rows'], 'fullName'));
        $leak = $admin->board($this->actor('superB'), $outlet, null);
        $this->assertSame([], $leak['rows']);
    }

    public function test_data_karyawan_dan_tautan_akun(): void
    {
        $A = static::$fixture['A'];
        $employees = app(Employees::class);
        $admin = $this->actor('adminA');
        $base = ['fullName' => 'Karyawan Baru QA', 'outletId' => $A['outletId'], 'position' => 'Barista', 'phone' => '0812 3456 7890', 'joinedAt' => '2026-11-01'];

        $id = $employees->create($admin, [...$base, 'employeeCode' => 'qa-new'], $this->audit());
        $this->assertSame('QA-NEW', DB::table('employees')->where('id', $id)->value('employee_code'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'employee.created', $id));
        $this->expectAccess('conflict', fn () => $employees->create($admin, [...$base, 'employeeCode' => 'QA-NEW'], $this->audit()), '/Kode karyawan sudah dipakai/');
        $this->expectAccess('conflict', fn () => $employees->create($admin, [...$base, 'employeeCode' => 'QA-DUP', 'userId' => static::$fixture['cashierA']['id']], $this->audit()), '/sudah ditautkan/');
        $this->expectAccess('invalid', fn () => $employees->create($admin, [...$base, 'employeeCode' => 'x'], $this->audit()), '/Kode karyawan/');
        $this->expectAccess('invalid', fn () => $employees->create($admin, [...$base, 'employeeCode' => 'QA-TEL', 'phone' => 'abc'], $this->audit()), '/telepon/');
        $this->expectAccess('invalid', fn () => $employees->create($admin, [...$base, 'employeeCode' => 'QA-OUT', 'outletId' => static::$fixture['B']['outletId']], $this->audit()), '/Outlet tidak ditemukan/');
        $this->expectAccess('forbidden', fn () => $employees->create($this->actor('cashierA'), [...$base, 'employeeCode' => 'QA-KS2'], $this->audit()));

        // Tautkan ke akun staf yang belum punya data karyawan, lalu nonaktifkan.
        $options = $employees->formOptions($admin, 'employee.edit', null);
        $this->assertContains(static::$fixture['loneA']['id'], array_column($options['accounts'], 'id'));
        $this->assertNotContains(static::$fixture['cashierA']['id'], array_column($options['accounts'], 'id'));
        $employees->update($admin, $id, [...$base, 'userId' => static::$fixture['loneA']['id'], 'isActive' => true], $this->audit());
        $this->assertSame(static::$fixture['loneA']['id'], DB::table('employees')->where('id', $id)->value('user_id'));
        $employees->update($admin, $id, [...$base, 'userId' => static::$fixture['loneA']['id'], 'isActive' => false], $this->audit());
        $this->assertFalse((bool) DB::table('employees')->where('id', $id)->value('is_active'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'employee.status_changed', $id));
        $this->expectAccess('forbidden', fn () => $this->engine()->checkIn($this->actor('loneA'), $this->at(), $this->selfie(), $this->audit()), '/nonaktif/');

        // Karyawan yang masih tercatat masuk tidak bisa dinonaktifkan.
        $open = $this->engine()->checkIn($this->actor('cashierA'), $this->at(), $this->selfie(), $this->audit())['id'];
        $this->expectAccess('conflict', fn () => $employees->update($admin, static::$fixture['empCashier'], ['fullName' => 'Kasir Absen QA', 'outletId' => $A['outletId'], 'userId' => static::$fixture['cashierA']['id'], 'isActive' => false], $this->audit()), '/masih tercatat masuk/');
        DB::table('attendances')->where('id', $open)->update(['check_out_at' => DB::raw('now()'), 'status' => 'checked_out', 'check_out_method' => 'manual_correction', 'working_minutes' => 0]);

        $list = $employees->list($this->actor('ownerA'), []);
        $this->assertContains('Kasir Absen QA', array_column($list['rows'], 'fullName'));
        $this->assertNotContains('Kasir Tenant B', array_column($list['rows'], 'fullName'));
        $this->assertSame(['Karyawan Baru QA'], array_column($employees->list($this->actor('ownerA'), ['status' => 'inactive'])['rows'], 'fullName'));
        $this->expectAccess('forbidden', fn () => $employees->list($this->actor('cashierA'), []));
        $this->expectAccess('not_found', fn () => $employees->get($this->actor('superB'), $id));
    }

    public function test_pengaturan_lokasi_outlet(): void
    {
        $A = static::$fixture['A'];
        $admin = app(AttendanceAdmin::class);
        $settings = $admin->settings($this->actor('adminA'));
        $outlet = collect($settings['outlets'])->firstWhere('id', $A['outletId']);
        $this->assertSame([true, 30], [$outlet['configured'], $outlet['radius']]);
        $this->assertTrue($settings['canEditCompany']);

        $this->expectAccess('invalid', fn () => $admin->updateGeofence($this->actor('adminA'), $A['outletId'], ['latitude' => '-6.9', 'longitude' => '', 'radius' => '30'], $this->audit()), '/berpasangan/');
        $this->expectAccess('invalid', fn () => $admin->updateGeofence($this->actor('adminA'), $A['outletId'], ['latitude' => '-6.9', 'longitude' => '107.6', 'radius' => '3'], $this->audit()), '/5–1.000/');
        $this->expectAccess('forbidden', fn () => $admin->updateGeofence($this->actor('managerA'), $A['outletId'], ['latitude' => '-6.9', 'longitude' => '107.6', 'radius' => '30'], $this->audit()));
        $this->expectAccess('not_found', fn () => $admin->updateGeofence($this->actor('superB'), $A['outletId'], ['latitude' => '-6.9', 'longitude' => '107.6', 'radius' => '30'], $this->audit()));
        $this->expectAccess('forbidden', fn () => $admin->settings($this->actor('ownerA')));

        $admin->updateGeofence($this->actor('adminA'), $A['outletId'], ['latitude' => (string) self::LAT, 'longitude' => (string) self::LNG, 'radius' => '50'], $this->audit());
        $this->assertSame(50, (int) DB::table('outlets')->where('id', $A['outletId'])->value('attendance_radius_meter'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'attendance.geofence_changed', $A['outletId']));
        // Radius baru langsung berlaku: 45 m kini diterima.
        $this->assertTrue($this->engine()->preview($this->actor('cashierA'), $this->at(0.0004))['ok']);
        $admin->updateGeofence($this->actor('adminA'), $A['outletId'], ['latitude' => (string) self::LAT, 'longitude' => (string) self::LNG, 'radius' => '30'], $this->audit());
    }

    public function test_retensi_foto_90_hari(): void
    {
        $A = static::$fixture['A'];
        $old = DB::table('attendances')->where('company_id', $A['id'])->whereNotNull('check_in_photo_path')->orderBy('check_in_at')->first();
        $recent = DB::table('attendances')->where('company_id', $A['id'])->whereNotNull('check_in_photo_path')->where('id', '<>', $old->id)->first();
        DB::table('attendances')->where('id', $old->id)->update(['check_in_at' => DB::raw("now() - interval '91 days'"), 'check_out_at' => DB::raw("now() - interval '91 days' + interval '1 hour'")]);

        $this->assertSame(0, Artisan::call('sudaya:purge-attendance-photos'));
        $after = DB::table('attendances')->where('id', $old->id)->first();
        $this->assertNull($after->check_in_photo_path);
        $this->assertNull($after->check_out_photo_path);
        $this->assertArrayNotHasKey($old->check_in_photo_path, self::$stored);
        // Data absen tetap ada; foto yang belum 90 hari tidak tersentuh.
        $this->assertSame($old->business_date, $after->business_date);
        $this->assertSame($recent->check_in_photo_path, DB::table('attendances')->where('id', $recent->id)->value('check_in_photo_path'));
        $this->assertArrayHasKey($recent->check_in_photo_path, self::$stored);
    }

    public function test_foto_wajah_terdaftar_sekali_dicek_admin_dan_bisa_direset(): void
    {
        // Keputusan owner 12 November 2026: absen memakai selfie yang dibandingkan admin dengan foto wajah terdaftar.
        $A = static::$fixture['A'];
        $employee = static::$fixture['empBarista'];
        $this->assertNull($this->engine()->state($this->actor('baristaA'))['face'], 'belum terdaftar');
        $this->expectAccess('invalid', fn () => $this->engine()->enrollFace($this->actor('baristaA'), $this->selfie(), false, $this->audit()), '/persetujuan/');
        $this->assertSame(0, DB::table('face_enrollments')->where('employee_id', $employee)->count(), 'tanpa persetujuan tidak tersimpan');

        $this->engine()->enrollFace($this->actor('baristaA'), $this->selfie(), true, $this->audit());
        $face = DB::table('face_enrollments')->where('employee_id', $employee)->whereNull('revoked_at')->first();
        $this->assertSame([AttendanceEngine::FACE_PROVIDER, static::$fixture['baristaA']['id']], [$face->provider, $face->created_by]);
        $this->assertStringStartsWith("faces/{$A['id']}/{$employee}/", $face->external_face_id);
        $this->assertArrayHasKey($face->external_face_id, self::$stored);
        $this->assertNotNull($face->consent_at);
        $this->assertNotNull($this->engine()->state($this->actor('baristaA'))['face']);
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'attendance.face_enrolled', $employee));
        // Tidak bisa ditukar sendiri.
        $this->expectAccess('conflict', fn () => $this->engine()->enrollFace($this->actor('baristaA'), $this->selfie(), true, $this->audit()), '/sudah terdaftar/');

        // Admin membandingkan: tanda di papan + foto terdaftar lewat server; kasir dan tenant lain tidak bisa.
        $admin = app(AttendanceAdmin::class);
        $outlet = ['id' => $A['outletId'], 'timezone' => DB::table('outlets')->where('id', $A['outletId'])->value('timezone')];
        $rows = collect($admin->board($this->actor('ownerA'), $outlet, null)['rows'])->keyBy('fullName');
        $this->assertSame([true, false], [$rows['Barista Absen QA']['hasFace'], $rows['Kasir Absen QA']['hasFace']]);
        $this->assertSame(self::$stored[$face->external_face_id], $admin->facePhoto($this->actor('ownerA'), $employee)['bytes']);
        $this->expectAccess('forbidden', fn () => $admin->facePhoto($this->actor('cashierA'), $employee));
        $this->expectAccess('not_found', fn () => $admin->facePhoto($this->actor('superB'), $employee));
        $this->expectAccess('forbidden', fn () => $admin->resetFace($this->actor('baristaA'), $employee, $this->audit()));

        // Reset admin: foto lama dihapus, tercatat, lalu karyawan mendaftar ulang.
        $admin->resetFace($this->actor('adminA'), $employee, $this->audit());
        $revoked = DB::table('face_enrollments')->where('id', $face->id)->first();
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame(static::$fixture['adminA']['id'], $revoked->revoked_by);
        $this->assertArrayNotHasKey($face->external_face_id, self::$stored);
        $this->assertNull($admin->facePhoto($this->actor('ownerA'), $employee));
        $this->assertNull($this->engine()->state($this->actor('baristaA'))['face']);
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'attendance.face_reset', $employee));
        $this->engine()->enrollFace($this->actor('baristaA'), $this->selfie(), true, $this->audit());
        $this->assertSame(1, DB::table('face_enrollments')->where('employee_id', $employee)->whereNull('revoked_at')->count());
    }
}
