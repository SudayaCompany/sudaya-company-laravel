<?php

namespace Tests\Live;

use App\Services\System\SystemHealth;

/** `sudaya:doctor` pada database Supabase yang berjalan: database, data awal, dan Supabase sehat. */
class SystemHealthTest extends LiveTestCase
{
    public function test_database_data_dan_supabase_dev_terbaca_sehat(): void
    {
        $failed = array_values(array_filter(
            app(SystemHealth::class)->run(),
            fn (array $check) => in_array($check['group'], ['Database', 'Data', 'Supabase'], true) && $check['status'] === SystemHealth::FAIL,
        ));

        $this->assertSame([], $failed);
    }
}
