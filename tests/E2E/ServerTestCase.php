<?php

namespace Tests\E2E;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Live\LiveTestCase;

/**
 * Menjalankan server PHP sungguhan (tanpa pengecualian CSRF untuk unit test) di
 * port terpisah, lalu menguji lewat HTTP. Server memakai sesi dan cache file,
 * sama seperti hosting, dan database dev yang sama dengan fixture.
 */
abstract class ServerTestCase extends LiveTestCase
{
    protected const PORT = 8123;

    /** @var resource|null */
    private static $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$server === null) {
            self::startServer();
        }
    }

    protected function base(): string
    {
        return 'http://127.0.0.1:'.self::PORT;
    }

    protected function browser(): Browser
    {
        return new Browser($this->base());
    }

    protected function login(array $user, ?string $next = null): Browser
    {
        $browser = $this->browser();
        $response = $browser->post('/login', array_filter(['email' => $user['email'], 'password' => $user['password'], 'next' => $next]));
        if ($response->getStatusCode() !== 302 || str_ends_with(Browser::location($response), '/login')) {
            throw new RuntimeException('login gagal: '.$response->getStatusCode());
        }

        return $browser;
    }

    /** Store token yang dipakai server (file, satu APP_KEY). */
    protected function storedTokens(string $userId): array
    {
        $sessionId = DB::table('auth.sessions')->where('user_id', $userId)->orderByDesc('created_at')->value('id');
        $stored = Cache::store('auth_tokens')->get("supabase-tokens:{$sessionId}");

        return [$sessionId, $stored === null ? null : json_decode(Crypt::decryptString($stored), true)];
    }

    protected function putTokens(string $sessionId, array $tokens): void
    {
        Cache::store('auth_tokens')->put("supabase-tokens:{$sessionId}", Crypt::encryptString(json_encode($tokens)), 600);
    }

    private static function startServer(): void
    {
        $env = [
            ...getenv(),
            // Nilai dari phpunit.live.xml (array, testing) tidak boleh ikut ke server.
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://127.0.0.1:'.self::PORT,
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'AUTH_TOKEN_CACHE_DRIVER' => 'file',
        ];
        $log = storage_path('logs/e2e-server.log');
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::PORT, '-t', public_path(), base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            // server.php memuat index.php dari direktori kerja, sama seperti `artisan serve`.
            public_path(),
            $env,
        );
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $code, $message, 1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(200_000);
        }
        throw new RuntimeException('server E2E tidak menyala');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }
}
