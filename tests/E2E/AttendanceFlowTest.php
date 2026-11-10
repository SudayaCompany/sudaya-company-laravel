<?php

namespace Tests\E2E;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Tests\Live\LiveContext;

/**
 * M7-lite lewat HTTP sungguhan: absen GPS + selfie (upload multipart ke bucket privat asli,
 * dibaca lagi lewat route berizin), koreksi lewat kotak Persetujuan, rekap + ekspor,
 * data karyawan, pengaturan, dan batas akses.
 */
class AttendanceFlowTest extends ServerTestCase
{
    private const LAT = -6.914744;

    private const LNG = 107.609810;

    protected function fixtures(LiveContext $ctx): array
    {
        $A = $ctx->createCompany('e2eabs');
        $users = [
            'admin' => $ctx->createUser($A, [['role' => 'super_admin']], true, 'E2E Admin Absen'),
            'owner' => $ctx->createUser($A, [['role' => 'owner']], true, 'E2E Owner Absen'),
            'cashier' => $ctx->createUser($A, [['role' => 'cashier', 'outlet' => 'outlet']], true, 'E2E Kasir Absen'),
            'barista' => $ctx->createUser($A, [['role' => 'barista', 'outlet' => 'outlet']], true, 'E2E Barista Tanpa Karyawan'),
        ];
        DB::table('outlets')->where('id', $A['outletId'])->update(['latitude' => self::LAT, 'longitude' => self::LNG, 'attendance_radius_meter' => 30]);
        $employee = DB::table('employees')->insertGetId([
            'company_id' => $A['id'], 'brand_id' => $A['brandId'], 'outlet_id' => $A['outletId'],
            'user_id' => $users['cashier']['id'], 'employee_code' => 'E2E-KSR', 'full_name' => 'Karyawan Kasir E2E', 'position' => 'Kasir',
        ]);

        return ['A' => $A, ...$users, 'employee' => $employee];
    }

    private function f(string $key): mixed
    {
        return static::$fixture[$key];
    }

    /** @return array<string, string> */
    private function at(float $dLat = 0.0001, int $accuracy = 10): array
    {
        return ['latitude' => (string) (self::LAT + $dLat), 'longitude' => (string) self::LNG, 'accuracy' => (string) $accuracy, 'ageMs' => '1500'];
    }

    private function photo(): array
    {
        // JPEG minimal yang sah (1×1 px), cukup untuk validasi magic bytes dan Storage.
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return ['photo' => ['name' => 'selfie.jpg', 'bytes' => $bytes, 'type' => 'image/jpeg']];
    }

    /** POST lalu ambil flash dari halaman tujuan redirect. */
    private function flash(Browser $browser, ResponseInterface $response): array
    {
        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        return Browser::page($browser->get(Browser::location($response)))['flash'] ?? [];
    }

    public function test_staf_absen_masuk_dan_pulang_lewat_http(): void
    {
        $cashier = $this->login($this->f('cashier'));
        $page = Browser::page($cashier->get('/staff/attendance'));
        $this->assertSame('staff/attendance', $page['component']);
        $this->assertSame('Karyawan Kasir E2E', $page['props']['employee']['fullName']);
        $this->assertNull($page['props']['open']);
        $nav = $page['props']['auth']['navigation'];
        $this->assertContains('/staff/attendance', $nav);

        $preview = json_decode((string) $cashier->json('/staff/attendance/location', $this->at())->getBody(), true);
        $this->assertTrue($preview['ok']);
        $this->assertTrue($preview['result']['ok']);
        $this->assertEqualsWithDelta(11.1, $preview['result']['distance'], 0.2);

        $cashier->get('/staff/attendance');
        $flash = $this->flash($cashier, $cashier->multipart('/staff/attendance/check-in', $this->at(), $this->photo()));
        $this->assertMatchesRegularExpression('/^Absen masuk tercatat pukul \d{2}\.\d{2}\. Selamat bekerja!$/', $flash['success'] ?? '');
        $open = Browser::page($cashier->get('/staff/attendance'))['props']['open'];
        $this->assertNotNull($open);
        $session = DB::table('attendances')->where('id', $open['id'])->first();
        $this->assertSame('gps_selfie', $session->check_in_method);
        $this->assertStringContainsString('GuzzleHttp', (string) $session->check_in_device);

        // Owner melihat papan + foto yang benar-benar tersimpan di bucket privat.
        $owner = $this->login($this->f('owner'));
        $board = Browser::page($owner->get('/admin/attendance'));
        $this->assertSame('admin/attendance/index', $board['component']);
        $row = collect($board['props']['board']['rows'])->firstWhere('fullName', 'Karyawan Kasir E2E');
        $this->assertSame('working', $row['status']);
        $this->assertTrue($row['sessions'][0]['hasCheckInPhoto']);
        $photo = $owner->get("/admin/attendance/{$open['id']}/photo/in");
        $this->assertSame(200, $photo->getStatusCode());
        $this->assertSame('image/jpeg', $photo->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('no-store', $photo->getHeaderLine('Cache-Control'));
        $this->assertSame("\xFF\xD8\xFF", substr((string) $photo->getBody(), 0, 3));
        $this->assertSame(403, $cashier->get("/admin/attendance/{$open['id']}/photo/in")->getStatusCode());
        $this->assertSame(404, $owner->get("/admin/attendance/{$open['id']}/photo/out")->getStatusCode(), 'belum ada foto pulang');

        $cashier->get('/staff/attendance');
        $flash = $this->flash($cashier, $cashier->multipart('/staff/attendance/check-out', $this->at(-0.0001), $this->photo()));
        $this->assertMatchesRegularExpression('/^Absen pulang tercatat pukul \d{2}\.\d{2}; total kerja 0 jam \d+ menit\. Terima kasih!$/', $flash['success'] ?? '');
        $this->assertSame('checked_out', DB::table('attendances')->where('id', $open['id'])->value('status'));
    }

    public function test_lokasi_luar_radius_tanpa_foto_dan_akun_tanpa_karyawan_ditolak(): void
    {
        $cashier = $this->login($this->f('cashier'));
        $cashier->get('/staff/attendance');
        $this->assertStringStartsWith('Anda di luar area absen (', $this->flash($cashier, $cashier->multipart('/staff/attendance/check-in', $this->at(0.001), $this->photo()))['error'] ?? '');
        $cashier->get('/staff/attendance');
        $this->assertSame('Foto selfie wajib diambil.', $this->flash($cashier, $cashier->multipart('/staff/attendance/check-in', $this->at()))['error'] ?? null);
        $this->assertSame(0, DB::table('attendances')->where('employee_id', $this->f('employee'))->whereNull('check_out_at')->count());

        $barista = $this->login($this->f('barista'));
        $this->assertNull(Browser::page($barista->get('/staff/attendance'))['props']['employee']);
        $barista->get('/staff/attendance');
        $this->assertStringContainsString('belum terhubung', $this->flash($barista, $barista->multipart('/staff/attendance/check-in', $this->at(), $this->photo()))['error'] ?? '');
    }

    public function test_koreksi_diputuskan_owner_di_kotak_persetujuan(): void
    {
        $tz = DB::table('outlets')->where('id', $this->f('A')['outletId'])->value('timezone');
        $date = CarbonImmutable::now($tz)->subDay()->toDateString();
        $cashier = $this->login($this->f('cashier'));
        $cashier->get('/staff/attendance/history');
        $flash = $this->flash($cashier, $cashier->post('/staff/attendance/corrections', ['kind' => 'missing_attendance', 'checkIn' => "{$date}T08:00", 'checkOut' => "{$date}T15:30", 'reason' => 'HP tertinggal']));
        $this->assertSame('Koreksi terkirim dan menunggu persetujuan Manager atau Owner.', $flash['success'] ?? null);

        $owner = $this->login($this->f('owner'));
        $inbox = Browser::page($owner->get('/admin/approvals'));
        $this->assertCount(1, $inbox['props']['corrections']);
        $correction = $inbox['props']['corrections'][0];
        $this->assertSame(['missing_attendance', 'Karyawan Kasir E2E', true], [$correction['kind'], $correction['employeeName'], $correction['canDecide']]);
        $flash = $this->flash($owner, $owner->post("/admin/approvals/{$correction['id']}/decide", ['approve' => true]));
        $this->assertSame('Koreksi absen disetujui.', $flash['success'] ?? null);
        $made = DB::table('attendances')->where('employee_id', $this->f('employee'))->where('business_date', $date)->first();
        $this->assertSame(['manual_correction', 450], [$made->check_in_method, (int) $made->working_minutes]);
        $this->assertSame([], Browser::page($owner->get('/admin/approvals'))['props']['corrections']);
    }

    public function test_rekap_ekspor_karyawan_pengaturan_dan_batas_akses(): void
    {
        $owner = $this->login($this->f('owner'));
        $report = Browser::page($owner->get('/admin/attendance/reports'));
        $this->assertSame('admin/attendance/reports', $report['component']);
        $this->assertSame(2, collect($report['props']['report']['rows'])->firstWhere('fullName', 'Karyawan Kasir E2E')['days']);
        $export = $owner->get('/admin/attendance/reports/export');
        $this->assertSame(200, $export->getStatusCode());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $export->getHeaderLine('Content-Type'));
        $this->assertSame('PK', substr((string) $export->getBody(), 0, 2));
        $this->assertSame(403, $owner->get('/admin/attendance/settings')->getStatusCode(), 'pengaturan hanya settings.manage');

        $admin = $this->login($this->f('admin'));
        $this->assertSame('admin/attendance/settings', Browser::page($admin->get('/admin/attendance/settings'))['component']);
        $admin->get('/admin/attendance/settings');
        $flash = $this->flash($admin, $admin->post('/admin/attendance/settings/outlets/'.$this->f('A')['outletId'], ['latitude' => (string) self::LAT, 'longitude' => (string) self::LNG, 'radius' => '35']));
        $this->assertSame('Lokasi absen outlet tersimpan.', $flash['success'] ?? null);
        $this->assertSame(35, (int) DB::table('outlets')->where('id', $this->f('A')['outletId'])->value('attendance_radius_meter'));

        $admin->get('/admin/employees/create');
        $created = $admin->post('/admin/employees', ['employeeCode' => 'e2e-brs', 'fullName' => 'Barista Baru E2E', 'outletId' => $this->f('A')['outletId'], 'userId' => $this->f('barista')['id']]);
        $this->assertSame('/admin/employees', Browser::location($created));
        $id = DB::table('employees')->where('employee_code', 'E2E-BRS')->where('company_id', $this->f('A')['id'])->value('id');
        $this->assertNotNull($id);
        $list = Browser::page($admin->get('/admin/employees'));
        $this->assertSame('admin/employees/index', $list['component']);
        $this->assertContains('Barista Baru E2E', array_column($list['props']['rows'], 'fullName'));
        $admin->get("/admin/employees/{$id}/edit");
        $updated = $admin->post("/admin/employees/{$id}", ['fullName' => 'Barista E2E', 'outletId' => $this->f('A')['outletId'], 'userId' => $this->f('barista')['id'], 'isActive' => true], true, 'PUT');
        $this->assertSame('/admin/employees', Browser::location($updated));
        $this->assertSame('Barista E2E', DB::table('employees')->where('id', $id)->value('full_name'));

        $cashier = $this->login($this->f('cashier'));
        foreach (['/admin/attendance', '/admin/attendance/reports', '/admin/employees', '/admin/attendance/settings'] as $path) {
            $this->assertSame(403, $cashier->get($path)->getStatusCode(), $path);
        }
        foreach (['/staff/attendance', '/admin/attendance', '/admin/employees'] as $path) {
            $guest = $this->browser()->get($path);
            $this->assertSame(302, $guest->getStatusCode(), $path);
            $this->assertStringStartsWith('/login', Browser::location($guest));
        }
    }

    public function test_tugas_luar_diajukan_disetujui_lalu_absen_dari_lokasi_tugas(): void
    {
        $tz = DB::table('outlets')->where('id', $this->f('A')['outletId'])->value('timezone');
        $tomorrow = CarbonImmutable::now($tz)->addDay()->toDateString();
        $cashier = $this->login($this->f('cashier'));
        $page = Browser::page($cashier->get('/staff/outside-duty'));
        $this->assertSame('staff/outside-duty', $page['component']);
        $this->assertSame('Karyawan Kasir E2E', $page['props']['employee']['fullName']);
        $this->assertContains('/staff/outside-duty', $page['props']['auth']['navigation']);

        $flash = $this->flash($cashier, $cashier->multipart('/staff/outside-duty', [
            'dutyDate' => $tomorrow, 'startTime' => '09:00', 'endTime' => '15:00', 'location' => 'Gudang pusat E2E', 'purpose' => 'Ambil biji kopi', 'notes' => 'Diminta owner E2E',
        ], ['photo' => ['name' => 'bukti.jpg', 'bytes' => $this->photo()['photo']['bytes'], 'type' => 'image/jpeg']]));
        $this->assertSame('Pengajuan tugas luar terkirim dan menunggu persetujuan Manager atau Owner.', $flash['success'] ?? null);
        $this->assertNotEmpty($this->flash($cashier, $cashier->post('/staff/outside-duty', ['dutyDate' => $tomorrow, 'startTime' => '10:00', 'endTime' => '11:00', 'location' => 'Gudang pusat E2E', 'purpose' => 'Ambil biji kopi', 'notes' => 'Diminta owner E2E']))['error'] ?? null, 'jam bertumpuk ditolak');

        $owner = $this->login($this->f('owner'));
        $inbox = Browser::page($owner->get('/admin/approvals'));
        $this->assertCount(1, $inbox['props']['outsideDuties']);
        $duty = $inbox['props']['outsideDuties'][0];
        $this->assertSame(['Karyawan Kasir E2E', 'Gudang pusat E2E', true], [$duty['employeeName'], $duty['location'], $duty['canDecide']]);
        $image = $owner->get($duty['evidenceUrl']);
        $this->assertSame([200, 'image/jpeg'], [$image->getStatusCode(), $image->getHeaderLine('Content-Type')]);
        $this->assertStringContainsString('no-store', $image->getHeaderLine('Cache-Control'));
        // Staf tanpa izin peninjau ditolak di penjaga rute /admin/outside-duty (sebelum service).
        $this->assertSame(403, $this->login($this->f('barista'))->get($duty['evidenceUrl'])->getStatusCode(), 'staf lain tidak melihat bukti');
        // Klien test tidak mengirim Referer: buka lagi kotak Persetujuan agar back() kembali ke sana
        // (browser asli mengirim Referer halaman Persetujuan, bukan URL foto).
        $owner->get('/admin/approvals');
        $flash = $this->flash($owner, $owner->post("/admin/approvals/{$duty['id']}/decide", ['approve' => true]));
        $this->assertSame('Tugas luar disetujui.', $flash['success'] ?? null);

        // Jendela tugas digeser ke sekarang (simulasi hari H; data test saja) lalu absen dari ±222 m.
        DB::table('outside_duty_requests')->where('id', $duty['requestId'])->update(['starts_at' => DB::raw("now() - interval '1 hour'"), 'ends_at' => DB::raw("now() + interval '2 hours'")]);
        $state = Browser::page($cashier->get('/staff/attendance'))['props'];
        $this->assertSame('Gudang pusat E2E', $state['outsideDuty']['location']);
        $flash = $this->flash($cashier, $cashier->multipart('/staff/attendance/check-in', $this->at(0.002), $this->photo()));
        $this->assertStringStartsWith('Absen masuk tercatat', $flash['success'] ?? '');
        $session = DB::table('attendances')->where('employee_id', $this->f('employee'))->whereNull('check_out_at')->first();
        $this->assertSame($duty['requestId'], $session->outside_duty_request_id);
        $flash = $this->flash($cashier, $cashier->multipart('/staff/attendance/check-out', $this->at(0.0025), $this->photo()));
        $this->assertStringStartsWith('Absen pulang tercatat', $flash['success'] ?? '');

        $mine = Browser::page($cashier->get('/staff/outside-duty'))['props']['requests'];
        $this->assertSame([['approved', true]], array_map(fn ($r) => [$r['status'], $r['active']], $mine));
        $board = Browser::page($owner->get("/admin/attendance?date={$tomorrow}"))['props']['outsideDuties'];
        $this->assertSame([$duty['requestId']], array_column($board, 'id'));

        // Batas akses: tamu ke login; Owner (tanpa izin absen) tidak membuka halaman staf ini.
        $guest = $this->browser()->get('/staff/outside-duty');
        $this->assertSame(302, $guest->getStatusCode());
        $this->assertStringStartsWith('/login', Browser::location($guest));
        $this->assertSame(403, $owner->get('/staff/outside-duty')->getStatusCode());
        $this->assertSame(1, DB::table('outside_duty_requests')->where('company_id', $this->f('A')['id'])->count(), 'hanya satu pengajuan tersimpan');
    }
}
