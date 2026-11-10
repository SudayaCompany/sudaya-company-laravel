<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Attendance\AttendanceAdmin;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendancePhotoStorage;
use App\Services\Attendance\OutsideDuty;
use App\Services\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Tugas luar (PRD §15, §69): pengajuan staf, persetujuan Manager/Owner (bukan pemohon),
 * absen di luar radius hanya selama jendela tugas yang disetujui, tolak/batal, foto bukti
 * berizin, retensi 90 hari, dan isolasi tenant.
 */
class OutsideDutyTest extends LiveTestCase
{
    private const LAT = -6.914744;

    private const LNG = 107.609810;

    public static array $stored = [];

    protected function fixtures(LiveContext $ctx): array
    {
        $A = $ctx->createCompany('duta');
        $B = $ctx->createCompany('dutb');
        $users = [
            'ownerA' => $ctx->createUser($A, [['role' => 'owner']]),
            'managerA' => $ctx->createUser($A, [['role' => 'manager', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']]),
            'loneA' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'superB' => $ctx->createUser($B, [['role' => 'super_admin']]),
            'cashierB' => $ctx->createUser($B, [['role' => 'cashier', 'outlet' => 'outlet']]),
        ];
        DB::table('outlets')->where('id', $A['outletId'])->update(['latitude' => self::LAT, 'longitude' => self::LNG, 'attendance_radius_meter' => 30]);
        $employee = fn (array $company, string $user, string $code, string $name) => DB::table('employees')->insertGetId([
            'company_id' => $company['id'], 'brand_id' => $company['brandId'], 'outlet_id' => $company['outletId'],
            'user_id' => $users[$user]['id'], 'employee_code' => $code, 'full_name' => $name,
        ]);

        return ['A' => $A, 'B' => $B, ...$users,
            'empCashier' => $employee($A, 'cashierA', 'TL-KSR', 'Kasir Tugas Luar QA'),
            'empManager' => $employee($A, 'managerA', 'TL-MGR', 'Manager Tugas Luar QA'),
            'empBarista' => $employee($A, 'baristaA', 'TL-BRS', 'Barista Tugas Luar QA'),
            'empB' => $employee($B, 'cashierB', 'TLB-KSR', 'Kasir Tenant B'),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AttendancePhotoStorage::class, new class implements AttendancePhotoStorage
        {
            public function upload(string $path, string $bytes, string $contentType): void
            {
                OutsideDutyTest::$stored[$path] = $bytes;
            }

            public function download(string $path): ?string
            {
                return OutsideDutyTest::$stored[$path] ?? null;
            }

            public function remove(array $paths): void
            {
                foreach ($paths as $path) {
                    unset(OutsideDutyTest::$stored[$path]);
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

    private function duties(): OutsideDuty
    {
        return app(OutsideDuty::class);
    }

    private function engine(): AttendanceEngine
    {
        return app(AttendanceEngine::class);
    }

    private function at(float $dLat, float $accuracy = 10): array
    {
        return ['latitude' => (string) (self::LAT + $dLat), 'longitude' => (string) self::LNG, 'accuracy' => (string) $accuracy, 'ageMs' => '1000'];
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('bukti.jpg', "\xFF\xD8\xFF\xE0".str_repeat('y', 2048));
    }

    private function day(int $offset): string
    {
        $tz = DB::table('outlets')->where('id', static::$fixture['A']['outletId'])->value('timezone');

        return CarbonImmutable::now($tz)->addDays($offset)->toDateString();
    }

    private function input(int $offset, string $start = '08:00', string $end = '17:00', array $overrides = []): array
    {
        return ['dutyDate' => $this->day($offset), 'startTime' => $start, 'endTime' => $end, 'location' => 'Pasar Induk QA', 'purpose' => 'Belanja bahan QA', 'notes' => 'Ditugaskan manager QA', ...$overrides];
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

    /** Geser jendela tugas agar sedang berlangsung (simulasi waktu berjalan; data test saja). */
    private function makeActive(string $requestId): void
    {
        DB::table('outside_duty_requests')->where('id', $requestId)->update([
            'starts_at' => DB::raw("now() - interval '1 hour'"),
            'ends_at' => DB::raw("now() + interval '1 hour'"),
        ]);
    }

    public function test_pengajuan_validasi_dan_tumpang_tindih(): void
    {
        $cashier = $this->actor('cashierA');
        $this->expectAccess('invalid', fn () => $this->duties()->request($cashier, $this->input(-1), null, $this->audit()), '/sudah lewat/');
        $this->expectAccess('invalid', fn () => $this->duties()->request($cashier, $this->input(1, '17:00', '08:00'), null, $this->audit()), '/^Jam selesai harus setelah jam mulai\.$/');
        $this->expectAccess('invalid', fn () => $this->duties()->request($cashier, $this->input(1, '08:00', '17:00', ['location' => 'ab']), null, $this->audit()), '/^Lokasi tugas minimal 3 karakter\.$/');
        $this->expectAccess('invalid', fn () => $this->duties()->request($cashier, $this->input(1, '08:00', '17:00', ['notes' => '']), null, $this->audit()), '/^Keterangan wajib diisi\.$/');
        $this->expectAccess('invalid', fn () => $this->duties()->request($cashier, $this->input(OutsideDuty::MAX_DAYS_AHEAD + 1), null, $this->audit()), '/paling jauh 60 hari/');
        $this->expectAccess('forbidden', fn () => $this->duties()->request($this->actor('loneA'), $this->input(1), null, $this->audit()), '/belum terhubung/');

        $created = $this->duties()->request($cashier, $this->input(1), $this->photo(), $this->audit());
        $row = DB::table('outside_duty_requests')->where('id', $created['requestId'])->first();
        $this->assertSame([static::$fixture['empCashier'], $this->day(1), 'Pasar Induk QA', 'Belanja bahan QA', $created['approvalId']], [$row->employee_id, $row->duty_date, $row->location, $row->purpose, $row->approval_id]);
        $this->assertSame(9 * 60, (CarbonImmutable::parse($row->ends_at)->getTimestamp() - CarbonImmutable::parse($row->starts_at)->getTimestamp()) / 60);
        $this->assertStringStartsWith('outside-duty/'.static::$fixture['A']['id'].'/'.static::$fixture['empCashier'].'/', $row->evidence_path);
        $this->assertArrayHasKey($row->evidence_path, self::$stored);
        $approval = DB::table('approvals')->where('id', $created['approvalId'])->first();
        $this->assertSame(['outside_duty', 'pending', $created['requestId']], [$approval->type, $approval->status, $approval->reference_id]);
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'outside_duty.requested', $created['requestId']));

        // Bertumpuk dengan pengajuan yang masih menunggu → ditolak; jam lain di hari yang sama boleh.
        $this->expectAccess('conflict', fn () => $this->duties()->request($cashier, $this->input(1, '16:00', '18:00'), null, $this->audit()), '/^Sudah ada pengajuan tugas luar pada jam tersebut\.$/');
        $this->duties()->request($cashier, $this->input(1, '17:00', '19:00'), null, $this->audit());
        $mine = $this->duties()->mine($cashier);
        $this->assertCount(2, $mine);
        $this->assertSame(['pending', 'pending'], array_column($mine, 'status'));
        $this->assertTrue(collect($mine)->firstWhere('id', $created['requestId'])['hasEvidence']);
    }

    public function test_persetujuan_lalu_absen_di_luar_radius(): void
    {
        $request = DB::table('outside_duty_requests')->where('employee_id', static::$fixture['empCashier'])->whereNotNull('evidence_path')->first();
        $outlet = ['id' => static::$fixture['A']['outletId']];
        $far = $this->at(0.002); // ±222 m dari outlet

        // Belum disetujui: radius outlet tetap berlaku, walau jendela tugas sedang berlangsung.
        $this->makeActive($request->id);
        $this->assertFalse($this->engine()->preview($this->actor('cashierA'), $far)['ok']);

        $inbox = $this->duties()->pending($this->actor('ownerA'), $outlet);
        $this->assertCount(2, $inbox);
        $item = collect($inbox)->firstWhere('requestId', $request->id);
        $this->assertSame(['Kasir Tugas Luar QA', 'Pasar Induk QA', true, "/admin/outside-duty/{$request->id}/evidence"], [$item['employeeName'], $item['location'], $item['canDecide'], $item['evidenceUrl']]);
        $this->expectAccess('forbidden', fn () => $this->duties()->pending($this->actor('cashierA'), $outlet));
        $this->expectAccess('forbidden', fn () => $this->duties()->decide($this->actor('cashierA'), ['approvalId' => $item['id'], 'approve' => true], $this->audit()));
        $this->expectAccess('invalid', fn () => $this->duties()->decide($this->actor('ownerA'), ['approvalId' => $item['id'], 'approve' => 'ya'], $this->audit()));

        $this->assertSame(['approved' => true], $this->duties()->decide($this->actor('ownerA'), ['approvalId' => $item['id'], 'approve' => true, 'note' => 'OK QA'], $this->audit()));
        $this->assertSame('approved', DB::table('approvals')->where('id', $item['id'])->value('status'));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'outside_duty.approved', $request->id));
        $this->expectAccess('conflict', fn () => $this->duties()->decide($this->actor('managerA'), ['approvalId' => $item['id'], 'approve' => false], $this->audit()), '/sudah diputuskan/');

        // Disetujui & aktif: di luar radius boleh (GPS dicatat); akurasi tetap wajib.
        $preview = $this->engine()->preview($this->actor('cashierA'), $far);
        $this->assertSame([true, 'outside_duty'], [$preview['ok'], $preview['mode']]);
        $this->assertSame([false, 'accuracy_too_low'], array_values(array_intersect_key($this->engine()->preview($this->actor('cashierA'), $this->at(0.002, 80)), ['ok' => 1, 'reason' => 1])));
        $state = $this->engine()->state($this->actor('cashierA'));
        $this->assertSame('Pasar Induk QA', $state['outsideDuty']['location']);

        $selfie = UploadedFile::fake()->createWithContent('selfie.jpg', "\xFF\xD8\xFF\xE0".str_repeat('z', 2048));
        $in = $this->engine()->checkIn($this->actor('cashierA'), $far, $selfie, $this->audit());
        $session = DB::table('attendances')->where('id', $in['id'])->first();
        $this->assertSame($request->id, $session->outside_duty_request_id);
        $this->assertEqualsWithDelta(222.4, (float) $session->check_in_distance_meter, 1.0);

        // Pulang setelah jendela berakhir: sesi tugas luar tetap boleh ditutup dari luar radius.
        DB::table('outside_duty_requests')->where('id', $request->id)->update(['ends_at' => DB::raw("now() - interval '1 minute'")]);
        $this->assertNull(OutsideDuty::active(static::$fixture['empCashier']));
        $selfie = UploadedFile::fake()->createWithContent('selfie.jpg', "\xFF\xD8\xFF\xE0".str_repeat('w', 2048));
        $this->engine()->checkOut($this->actor('cashierA'), $this->at(0.003), $selfie, $this->audit());
        $this->assertNotNull(DB::table('attendances')->where('id', $in['id'])->value('check_out_at'));

        // Papan kehadiran pada tanggal tugas menampilkan tugas luar yang disetujui.
        $board = $this->duties()->forDate($this->actor('ownerA'), $outlet, $request->duty_date);
        $this->assertSame([$request->id], array_column($board, 'id'));
        $this->assertSame([], $this->duties()->forDate($this->actor('cashierA'), $outlet, $request->duty_date));
    }

    public function test_pemohon_manager_tidak_bisa_menyetujui_sendiri(): void
    {
        $created = $this->duties()->request($this->actor('managerA'), $this->input(2), null, $this->audit());
        $this->expectAccess('forbidden', fn () => $this->duties()->decide($this->actor('managerA'), ['approvalId' => $created['approvalId'], 'approve' => true], $this->audit()), '/pengajuan Anda sendiri/');
        $item = collect($this->duties()->pending($this->actor('managerA'), ['id' => static::$fixture['A']['outletId']]))->firstWhere('id', $created['approvalId']);
        $this->assertFalse($item['canDecide']);
        $this->duties()->decide($this->actor('ownerA'), ['approvalId' => $created['approvalId'], 'approve' => true], $this->audit());
        $this->assertSame('approved', DB::table('approvals')->where('id', $created['approvalId'])->value('status'));
    }

    public function test_ditolak_dibatalkan_dan_isolasi(): void
    {
        $barista = $this->actor('baristaA');
        $rejected = $this->duties()->request($barista, $this->input(3), $this->photo(), $this->audit());
        $this->duties()->decide($this->actor('managerA'), ['approvalId' => $rejected['approvalId'], 'approve' => false, 'note' => 'Tidak ada tugas'], $this->audit());
        $this->makeActive($rejected['requestId']);
        $this->assertNull(OutsideDuty::active(static::$fixture['empBarista']), 'ditolak tidak pernah aktif');
        $preview = $this->engine()->preview($barista, $this->at(0.002));
        $this->assertSame([false, 'outside_radius'], [$preview['ok'], $preview['reason']]);
        $this->expectAccess('invalid', fn () => $this->engine()->checkIn($barista, $this->at(0.002), $this->photo(), $this->audit()), '/di luar area absen/');
        $mine = collect($this->duties()->mine($barista))->firstWhere('id', $rejected['requestId']);
        $this->assertSame(['rejected', 'Ditolak', 'Tidak ada tugas'], [$mine['status'], $mine['statusLabel'], $mine['decisionNote']]);

        $cancel = $this->duties()->request($barista, $this->input(4), null, $this->audit());
        $this->expectAccess('not_found', fn () => $this->duties()->cancel($this->actor('cashierA'), $cancel['requestId'], $this->audit()));
        $this->duties()->cancel($barista, $cancel['requestId'], $this->audit());
        $this->assertSame('cancelled', DB::table('approvals')->where('id', $cancel['approvalId'])->value('status'));
        $this->expectAccess('conflict', fn () => $this->duties()->cancel($barista, $cancel['requestId'], $this->audit()), '/sudah diputuskan/');
        $this->expectAccess('conflict', fn () => $this->duties()->cancel($barista, $rejected['requestId'], $this->audit()));
        $this->assertSame(1, $this->ctx()->countAudit(static::$fixture['A']['id'], 'outside_duty.cancelled', $cancel['requestId']));
        // Pengajuan yang dibatalkan tidak lagi menghalangi jam yang sama.
        $this->duties()->request($barista, $this->input(4), null, $this->audit());

        // Foto bukti: peninjau outlet boleh; staf lain dan tenant lain tidak.
        $evidence = $this->duties()->evidence($this->actor('ownerA'), $rejected['requestId']);
        $this->assertSame('image/jpeg', $evidence['contentType']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $evidence['bytes']);
        $this->assertSame($evidence['bytes'], $this->duties()->evidence($barista, $rejected['requestId'])['bytes'], 'pemohon melihat buktinya sendiri');
        $this->expectAccess('not_found', fn () => $this->duties()->evidence($this->actor('cashierA'), $rejected['requestId']));
        $this->expectAccess('not_found', fn () => $this->duties()->evidence($this->actor('superB'), $rejected['requestId']));
        $this->expectAccess('not_found', fn () => $this->duties()->decide($this->actor('superB'), ['approvalId' => $rejected['approvalId'], 'approve' => true], $this->audit()));
        $this->assertSame([], $this->duties()->pending($this->actor('superB'), ['id' => static::$fixture['A']['outletId']]));
        $this->assertSame([], $this->duties()->mine($this->actor('cashierB')));
    }

    public function test_retensi_bukti_tugas_luar_90_hari(): void
    {
        $rows = DB::table('outside_duty_requests')->where('company_id', static::$fixture['A']['id'])->whereNotNull('evidence_path')->orderBy('created_at')->get();
        $this->assertCount(2, $rows);
        [$old, $recent] = [$rows[0], $rows[1]];
        DB::table('outside_duty_requests')->where('id', $old->id)->update(['starts_at' => DB::raw("now() - interval '92 days'"), 'ends_at' => DB::raw("now() - interval '91 days'")]);

        $this->assertSame(0, Artisan::call('sudaya:purge-attendance-photos'));
        $this->assertNull(DB::table('outside_duty_requests')->where('id', $old->id)->value('evidence_path'));
        $this->assertArrayNotHasKey($old->evidence_path, self::$stored);
        $this->assertSame('Pasar Induk QA', DB::table('outside_duty_requests')->where('id', $old->id)->value('location'), 'data pengajuan tetap ada');
        $this->assertSame($recent->evidence_path, DB::table('outside_duty_requests')->where('id', $recent->id)->value('evidence_path'));
        $this->assertArrayHasKey($recent->evidence_path, self::$stored);
    }

    public function test_ekspor_absensi_memuat_detail_payroll(): void
    {
        $A = static::$fixture['A'];
        $tz = DB::table('outlets')->where('id', $A['outletId'])->value('timezone');
        $outlet = ['id' => $A['outletId'], 'name' => 'Outlet Tugas Luar QA', 'timezone' => $tz];
        $today = CarbonImmutable::now($tz)->toDateString();
        $admin = app(AttendanceAdmin::class);
        $row = collect($admin->report($this->actor('ownerA'), $outlet, $today, $today)['rows'])->firstWhere('fullName', 'Kasir Tugas Luar QA');
        $this->assertSame([1, 1, 0], [$row['sessions'], $row['outside'], $row['incomplete']]);

        $file = $admin->exportReport($this->actor('ownerA'), $outlet, $today, $today);
        $path = tempnam(sys_get_temp_dir(), 'odx');
        file_put_contents($path, $file['bytes']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        [$workbook, $summary, $detail] = [$zip->getFromName('xl/workbook.xml'), $zip->getFromName('xl/worksheets/sheet1.xml'), $zip->getFromName('xl/worksheets/sheet2.xml')];
        $zip->close();
        unlink($path);
        $this->assertStringContainsString('<sheet name="Rekap absensi" sheetId="1"', $workbook);
        $this->assertStringContainsString('<sheet name="Detail absen" sheetId="2"', $workbook);
        $this->assertStringContainsString('Sesi tugas luar', $summary);
        foreach (['Jam masuk', 'Jam pulang', 'Kasir Tugas Luar QA', 'GPS + selfie', '>Ya<'] as $needle) {
            $this->assertStringContainsString($needle, $detail);
        }
        $session = DB::table('attendances')->where('employee_id', static::$fixture['empCashier'])->first(['check_in_at']);
        $this->assertStringContainsString('>'.CarbonImmutable::parse($session->check_in_at)->setTimezone($tz)->format('H:i').'<', $detail, 'jam masuk waktu lokal outlet');
    }
}
