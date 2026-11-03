<?php

namespace Database\Seeders;

use App\Services\Seed\InitialData;
use Illuminate\Database\Seeder;

/** Data awal Sudaya. Aman dijalankan ulang, lihat InitialData. */
class DatabaseSeeder extends Seeder
{
    public function run(InitialData $data): void
    {
        $report = $data->seed();

        $this->command?->info(json_encode($report));
    }
}
