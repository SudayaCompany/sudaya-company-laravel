<?php

namespace Tests\Live;

use App\Services\Storage\StorageBuckets;
use App\Support\Database\BaselineSchema;
use Illuminate\Support\Facades\DB;

/**
 * Database Supabase yang berjalan (dev) dikenali sebagai sudah terpasang, jadi migration
 * schema dasar tidak pernah memasang ulang di sana. Hanya membaca.
 */
class BaselineSchemaTest extends LiveTestCase
{
    public function test_database_berjalan_terbaca_lengkap_dan_prasyarat_terpenuhi(): void
    {
        $this->assertSame(BaselineSchema::INSTALLED, BaselineSchema::state());
        $this->assertSame([], BaselineSchema::missingPrerequisites());
        $this->assertTrue((bool) DB::scalar("select relrowsecurity from pg_class where oid = 'public.migrations'::regclass"));
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_tables where schemaname = 'public' and not rowsecurity"));
    }

    public function test_bucket_storage_project_sesuai_kebijakan(): void
    {
        $buckets = app(StorageBuckets::class);

        $this->assertSame(['ok', 'ok', 'ok'], array_column($buckets->sync(apply: false), 'status'));
        $this->assertSame([], $buckets->unexpectedPublic());
    }
}
