<?php

namespace Tests\Live;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * Test ke Supabase dev sungguhan. Fixture dibuat sekali per kelas test dan dihapus
 * di akhir kelas. Tidak memakai RefreshDatabase: database ini berisi data asli.
 */
abstract class LiveTestCase extends TestCase
{
    protected static ?LiveContext $ctx = null;

    /** @var array<string, mixed> fixture per kelas */
    protected static array $fixture = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql' || ! config('services.supabase.service_role_key')) {
            $this->markTestSkipped('Test live butuh DB_URL dan kunci Supabase di .env (jalankan dengan phpunit.live.xml).');
        }
        if ($this->app->isProduction()) {
            $this->fail('Test live tidak boleh dijalankan dengan APP_ENV=production.');
        }
        if (static::$ctx === null) {
            static::$ctx = new LiveContext;
            static::$fixture = $this->fixtures(static::$ctx);
        }
    }

    /** @return array<string, mixed> */
    protected function fixtures(LiveContext $ctx): array
    {
        return [];
    }

    protected function ctx(): LiveContext
    {
        return static::$ctx;
    }

    protected function tearDown(): void
    {
        // Batas session pooler 15 klien: jangan biarkan koneksi menggantung antar test.
        DB::disconnect();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$ctx !== null) {
            $app = require __DIR__.'/../../bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            try {
                static::$ctx->cleanup();
            } finally {
                DB::disconnect();
                static::$ctx = null;
                static::$fixture = [];
                $app->flush();
                Facade::clearResolvedInstances();
                // App sementara ini memasang error handler; tanpa dilepas, test kelas berikutnya ditandai risky.
                HandleExceptions::flushState();
            }
        }
        parent::tearDownAfterClass();
    }
}
