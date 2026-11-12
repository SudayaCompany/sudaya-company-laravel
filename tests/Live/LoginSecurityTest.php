<?php

namespace Tests\Live;

use App\Services\Audit\AuditLogger;
use App\Services\Security\LoginLimits;
use App\Services\Security\RateLimits;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

/** Padanan tests/integration/batch1-security.test.ts. */
class LoginSecurityTest extends LiveTestCase
{
    private const WRONG = 'Email atau password salah.';

    private const LIMIT = '/^Terlalu banyak percobaan login\. Coba lagi dalam \d+ menit\.$/';

    private const ATTACKER_IP = '203.0.113.10';

    private const OWNER_IP = '203.0.113.20';

    private const NEW_IP = '203.0.113.30';

    protected function fixtures(LiveContext $ctx): array
    {
        $company = $ctx->createCompany('sec1');
        app(AuditLogger::class)->record(['company_id' => $company['id'], 'actor_id' => null, 'action' => 'test.audit_seed']);

        return ['company' => $company, 'staff' => $ctx->createUser($company, [['role' => 'barista']], true)];
    }

    private function track(string $email, string ...$ips): void
    {
        foreach (LoginLimits::rules($email, null) as $rule) {
            $this->ctx()->trackRateLimitKey($rule['key']);
        }
        foreach ($ips as $ip) {
            foreach (LoginLimits::rules($email, $ip) as $rule) {
                $this->ctx()->trackRateLimitKey($rule['key']);
            }
            $this->ctx()->trackRateLimitKey(LoginLimits::knownNetworkRule($email, $ip)['key']);
        }
    }

    /** Counter diisi langsung agar tidak memicu rate limit Supabase Auth sendiri. */
    private function seedCounter(string $key, int $count, string $expiresIn = '15 minutes'): void
    {
        DB::statement(
            'insert into rate_limit_counters (key, count, expires_at) values (?, ?, now() + ?::interval)
             on conflict (key) do update set count = excluded.count, expires_at = excluded.expires_at',
            [RateLimits::hash($key), $count, $expiresIn],
        );
    }

    private function counter(string $key): ?object
    {
        return DB::table('rate_limit_counters')->where('key', RateLimits::hash($key))->first();
    }

    private function freshEmail(string $label): string
    {
        return "sudaya-test-{$label}-".bin2hex(random_bytes(4)).'@example.com';
    }

    private function login(string $email, string $password, ?string $ip = null)
    {
        config(['sudaya.trusted_ip_header' => $ip === null ? null : 'X-Test-Ip']);

        return $this->withHeaders(array_filter(['X-Test-Ip' => $ip, 'User-Agent' => 'phpunit', 'X-Forwarded-For' => '6.6.6.6']))
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** Sesi berformat JSON: setelah request, bag error tersimpan sebagai array. */
    private function formError($response): ?string
    {
        $errors = session('errors');
        if ($errors instanceof ViewErrorBag) {
            return $errors->first('form') ?: null;
        }

        return $errors['default']['messages']['form'][0] ?? null;
    }

    public function test_tebak_password_berhenti_di_batas_dengan_pesan_yang_sama_untuk_email_tak_dikenal(): void
    {
        $company = static::$fixture['company'];
        $staff = static::$fixture['staff'];
        $this->track($staff['email']);
        $this->seedCounter("login:email:{$staff['email']}", LoginLimits::ACCOUNT_IP - 2);

        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(self::WRONG, $this->formError($this->login($staff['email'], 'password-salah-123')));
        }
        $this->assertMatchesRegularExpression(self::LIMIT, $blocked = $this->formError($this->login($staff['email'], $staff['password'])));
        $this->assertSame(LoginLimits::ACCOUNT_IP, $this->counter("login:email:{$staff['email']}")->count);
        $this->assertGuest();

        $failed = DB::table('audit_logs')->where('company_id', $company['id'])->where('action', 'auth.login_failed')->get();
        $this->assertCount(2, $failed);
        $this->assertNull($failed[0]->actor_id);
        $this->assertSame('user', $failed[0]->entity_type);
        $this->assertSame($staff['id'], $failed[0]->entity_id);
        $this->assertNull($failed[0]->ip);
        $this->assertSame('phpunit', $failed[0]->user_agent);
        $this->assertStringNotContainsString('password-salah-123', json_encode($failed));
        $this->assertSame(1, $this->ctx()->countAudit($company['id'], 'auth.login_limited', $staff['id']));

        $unknown = $this->freshEmail('unknown');
        $this->track($unknown);
        $this->seedCounter("login:email:{$unknown}", LoginLimits::ACCOUNT_IP - 1);
        $this->assertSame(self::WRONG, $this->formError($this->login($unknown, 'password-salah-123')));
        $this->assertSame(LoginLimits::ACCOUNT_IP, $this->counter("login:email:{$unknown}")->count);
        $unknownBlocked = $this->formError($this->login($unknown, 'password-salah-123'));
        $this->assertSame(preg_replace('/\d+/', 'N', $blocked), preg_replace('/\d+/', 'N', $unknownBlocked));
    }

    public function test_email_tak_dikenal_tidak_masuk_audit(): void
    {
        $email = $this->freshEmail('noaudit');
        $this->track($email);
        $this->assertSame(self::WRONG, $this->formError($this->login($email, 'password-salah-123')));
        $this->assertSame(0, DB::table('audit_logs')->where('after', 'like', "%{$email}%")->orWhere('reason', 'like', "%{$email}%")->count());
    }

    public function test_ip_penyerang_di_batas_pemilik_akun_dari_ip_lain_tetap_bisa_login(): void
    {
        $victim = $this->ctx()->createUser(static::$fixture['company'], [['role' => 'barista']], true);
        $this->track($victim['email'], self::ATTACKER_IP, self::OWNER_IP);
        $this->seedCounter("login:email-ip:{$victim['email']}:".self::ATTACKER_IP, LoginLimits::ACCOUNT_IP);

        $this->assertMatchesRegularExpression(self::LIMIT, $this->formError($this->login($victim['email'], $victim['password'], self::ATTACKER_IP)));

        $this->login($victim['email'], $victim['password'], self::OWNER_IP)->assertRedirect('/barista');
        $this->assertSame(0, $this->counter("login:email-ip:{$victim['email']}:".self::OWNER_IP)->count);
        $this->assertSame(1, $this->counter(LoginLimits::knownNetworkRule($victim['email'], self::OWNER_IP)['key'])->count);
    }

    public function test_batas_seluruh_akun_jaringan_dikenal_lolos_jaringan_baru_ditahan(): void
    {
        $company = static::$fixture['company'];
        $victim = $this->ctx()->createUser($company, [['role' => 'barista']], true);
        $this->track($victim['email'], self::OWNER_IP, self::NEW_IP);
        app(LoginLimits::class)->rememberNetwork($victim['email'], self::OWNER_IP);
        $this->seedCounter("login:email:{$victim['email']}", LoginLimits::ACCOUNT);

        $this->assertMatchesRegularExpression(self::LIMIT, $this->formError($this->login($victim['email'], $victim['password'], self::NEW_IP)));

        $this->login($victim['email'], $victim['password'], self::OWNER_IP)->assertRedirect('/barista');
        $this->assertSame(1, $this->ctx()->countAudit($company['id'], 'auth.login', $victim['id']));
    }

    public function test_password_benar_milik_akun_nonaktif_bukan_kegagalan(): void
    {
        $company = static::$fixture['company'];
        $inactive = $this->ctx()->createUser($company, [['role' => 'barista']], true);
        $this->track($inactive['email']);
        DB::table('users')->where('id', $inactive['id'])->update(['is_active' => false]);

        $this->assertStringContainsString('dinonaktifkan', $this->formError($this->login($inactive['email'], $inactive['password'])));
        $this->assertGuest();
        $this->assertSame(0, $this->counter("login:email:{$inactive['email']}")->count);
        $this->assertSame(0, $this->ctx()->countAudit($company['id'], 'auth.login_failed', $inactive['id']));
        // Session Supabase yang sempat dibuat langsung ditutup.
        $this->assertSame(0, DB::table('auth.sessions')->where('user_id', $inactive['id'])->count());
    }

    public function test_akun_auth_yang_bukan_staf_ditolak_dan_session_supabase_ditutup(): void
    {
        $outsider = $this->ctx()->createAuthAccount();
        $this->track($outsider['email']);

        $this->assertSame('Akun ini belum terdaftar sebagai staf Sudaya.', $this->formError($this->login($outsider['email'], $outsider['password'])));
        $this->assertGuest();
        $this->assertSame(0, DB::table('auth.sessions')->where('user_id', $outsider['id'])->count());
    }

    public function test_staf_tanpa_role_ditolak(): void
    {
        $noRole = $this->ctx()->createUser(static::$fixture['company'], [], true);
        $this->track($noRole['email']);

        $this->assertSame('Akun Anda belum memiliki akses. Hubungi admin.', $this->formError($this->login($noRole['email'], $noRole['password'])));
        $this->assertGuest();
    }

    public function test_validasi_input_login(): void
    {
        $this->post('/login', ['email' => '', 'password' => ''])->assertSessionHasErrors([
            'email' => 'Email wajib diisi.',
            'password' => 'Password wajib diisi.',
        ]);
        $this->post('/login', ['email' => 'bukan-email', 'password' => 'x'])->assertSessionHasErrors(['email' => 'Format email tidak valid.']);
        // Password tidak pernah dikembalikan ke form.
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_percobaan_yang_ditolak_tidak_menaikkan_counter(): void
    {
        $email = $this->freshEmail('blocked');
        $this->track($email, self::ATTACKER_IP);
        $this->seedCounter("login:email-ip:{$email}:".self::ATTACKER_IP, LoginLimits::ACCOUNT_IP);
        $before = $this->counter("login:email-ip:{$email}:".self::ATTACKER_IP);

        for ($i = 0; $i < 2; $i++) {
            $this->assertFalse(app(LoginLimits::class)->begin($email, self::ATTACKER_IP)['ok']);
        }

        $this->assertEquals($before, $this->counter("login:email-ip:{$email}:".self::ATTACKER_IP));
        $this->assertNull($this->counter("login:email:{$email}"));
    }

    /** PHP tidak berbagi memori antar request, jadi paralelnya memakai proses terpisah. */
    public function test_burst_paralel_tidak_melewati_batas(): void
    {
        $email = $this->freshEmail('burst');
        $this->track($email);
        // 12 proses: di atas batas 10, tetap di bawah batas 15 klien session pooler.
        $processes = [];
        for ($i = 0; $i < 12; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, __DIR__.'/bin/login-attempt.php', $email],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            $outputs[] = $pipes;
        }
        $results = [];
        foreach ($processes as $index => $process) {
            $results[] = trim(stream_get_contents($outputs[$index][1]));
            $error = stream_get_contents($outputs[$index][2]);
            proc_close($process);
            $this->assertSame('', trim($error));
        }

        $this->assertSame(LoginLimits::ACCOUNT_IP, count(array_filter($results, fn ($r) => $r === 'ok')));
        $this->assertSame(LoginLimits::ACCOUNT_IP, $this->counter("login:email:{$email}")->count);
    }

    public function test_reservasi_dikembalikan_dan_counter_tidak_pernah_di_bawah_0(): void
    {
        $email = $this->freshEmail('release');
        $this->track($email);
        $limits = app(LoginLimits::class);
        $attempt = $limits->begin($email, null);
        $this->assertSame(1, $this->counter("login:email:{$email}")->count);
        $limits->release($attempt);
        $limits->release($attempt);
        $this->assertSame(0, $this->counter("login:email:{$email}")->count);
    }

    public function test_pulih_setelah_jendela_waktu_lewat(): void
    {
        $email = $this->freshEmail('window');
        $this->track($email);
        $this->seedCounter("login:email:{$email}", LoginLimits::ACCOUNT_IP, '-1 second');

        $this->assertTrue(app(LoginLimits::class)->begin($email, null)['ok']);
        $this->assertSame(1, $this->counter("login:email:{$email}")->count);
    }

    public function test_audit_log_menolak_update_delete_dan_truncate(): void
    {
        $seeded = fn () => DB::table('audit_logs')->where('company_id', static::$fixture['company']['id'])->where('action', 'test.audit_seed');

        foreach ([
            fn () => $seeded()->update(['reason' => 'ubah']),
            fn () => $seeded()->delete(),
            fn () => DB::statement('truncate table audit_logs'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('audit log berubah');
            } catch (QueryException $error) {
                $this->assertSame('P0001', $error->getCode());
            }
        }
        $this->assertSame(1, $seeded()->count());
    }

    public function test_flag_maintenance_hanya_berlaku_di_transaksi_yang_sama_dan_hanya_untuk_delete(): void
    {
        $companyId = static::$fixture['company']['id'];
        app(AuditLogger::class)->record(['company_id' => $companyId, 'actor_id' => null, 'action' => 'test.audit_bypass']);
        $row = fn () => DB::table('audit_logs')->where('company_id', $companyId)->where('action', 'test.audit_bypass');

        try {
            DB::transaction(function () use ($row) {
                DB::select("select set_config('sudaya.audit_maintenance', 'on', true)");
                $row()->update(['reason' => 'ubah']);
            });
            $this->fail('update lolos');
        } catch (QueryException $error) {
            $this->assertSame('P0001', $error->getCode());
        }

        DB::transaction(fn () => DB::select("select set_config('sudaya.audit_maintenance', 'on', true)"));
        try {
            $row()->delete();
            $this->fail('delete di luar transaksi lolos');
        } catch (QueryException $error) {
            $this->assertSame('P0001', $error->getCode());
        }
        $this->assertSame(1, $row()->count());

        DB::transaction(function () use ($row) {
            DB::select("select set_config('sudaya.audit_maintenance', 'on', true)");
            $row()->delete();
        });
        $this->assertSame(0, $row()->count());
    }
}
