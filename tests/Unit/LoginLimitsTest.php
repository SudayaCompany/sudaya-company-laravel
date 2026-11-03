<?php

namespace Tests\Unit;

use App\Services\Security\ClientIp;
use App\Services\Security\LoginLimits;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Padanan tests/unit/batch1-security.test.ts (bagian batas login dan IP audit). */
class LoginLimitsTest extends TestCase
{
    public function test_tanpa_ip_tepercaya_hanya_satu_batas_per_email(): void
    {
        $this->assertSame(
            [['key' => 'login:email:a@sudaya.test', 'limit' => LoginLimits::ACCOUNT_IP, 'windowSeconds' => LoginLimits::WINDOW_SECONDS]],
            LoginLimits::rules('a@sudaya.test', null),
        );
    }

    public function test_dengan_ip_tepercaya_memakai_email_ip_ip_dan_email(): void
    {
        $window = LoginLimits::WINDOW_SECONDS;
        $this->assertSame([
            ['key' => 'login:email-ip:a@sudaya.test:203.0.113.7', 'limit' => LoginLimits::ACCOUNT_IP, 'windowSeconds' => $window],
            ['key' => 'login:ip:203.0.113.7', 'limit' => LoginLimits::IP, 'windowSeconds' => $window],
            ['key' => 'login:email:a@sudaya.test', 'limit' => LoginLimits::ACCOUNT, 'windowSeconds' => $window],
        ], LoginLimits::rules('a@sudaya.test', '203.0.113.7'));
    }

    public function test_jaringan_yang_pernah_sukses_tidak_terkena_batas_seluruh_akun(): void
    {
        $this->assertSame(
            ['login:email-ip:a@sudaya.test:203.0.113.7', 'login:ip:203.0.113.7'],
            array_column(LoginLimits::rules('a@sudaya.test', '203.0.113.7', true), 'key'),
        );
    }

    public function test_pesan_menyebut_sisa_menit_dibulatkan_ke_atas(): void
    {
        $this->assertStringContainsString('2 menit', LoginLimits::message(61));
        $this->assertStringContainsString('1 menit', LoginLimits::message(30));
    }

    public function test_mengabaikan_x_forwarded_for_palsu_tanpa_proxy_tepercaya(): void
    {
        config(['sudaya.trusted_ip_header' => null]);
        $request = Request::create('/', server: ['HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '5.6.7.8']);
        $this->assertNull(ClientIp::trusted($request));
    }

    public function test_memakai_header_proxy_tepercaya_bukan_x_forwarded_for(): void
    {
        config(['sudaya.trusted_ip_header' => 'X-Real-IP']);
        $request = Request::create('/', server: ['HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '203.0.113.7']);
        $this->assertSame('203.0.113.7', ClientIp::trusted($request));
    }

    public function test_nilai_header_yang_bukan_ip_ditolak(): void
    {
        config(['sudaya.trusted_ip_header' => 'X-Real-IP']);
        $this->assertNull(ClientIp::trusted(Request::create('/', server: ['HTTP_X_REAL_IP' => '<script>'])));
        $this->assertSame('203.0.113.9', ClientIp::trusted(Request::create('/', server: ['HTTP_X_REAL_IP' => '10.0.0.1, 203.0.113.9'])));
    }
}
