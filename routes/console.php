<?php

use App\Services\System\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retensi foto absen 90 hari (keputusan owner 9 November). Server butuh cron tiap menit:
// `* * * * * php /path/artisan schedule:run`.
Schedule::command('sudaya:purge-attendance-photos')->timezone('Asia/Jakarta')->dailyAt('03:15')->withoutOverlapping();

// Tanda bahwa cron schedule:run benar-benar berjalan, dibaca `sudaya:doctor`.
Schedule::call(fn () => touch(storage_path(SystemHealth::HEARTBEAT)))->name('sudaya-heartbeat')->everyFiveMinutes();

// Backup harian database 02.30 WIB (pg_dump), hanya bila BACKUP_DIR diisi. Disimpan 14 hari.
Schedule::command('sudaya:backup')->timezone('Asia/Jakarta')->dailyAt('02:30')
    ->when(fn () => (string) config('sudaya.backup_dir') !== '')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));
