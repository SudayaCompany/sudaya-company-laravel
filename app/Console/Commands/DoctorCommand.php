<?php

namespace App\Console\Commands;

use App\Services\System\SystemHealth;
use Illuminate\Console\Command;

/**
 * Pemeriksaan kesehatan sistem: konfigurasi, server, database, job otomatis, data awal,
 * Supabase (login & storage), cron, backup, dan error log. Hanya membaca.
 * Dipakai setelah deploy dan saat perawatan rutin (docs/PANDUAN_SUPERADMIN.md).
 */
class DoctorCommand extends Command
{
    protected $signature = 'sudaya:doctor {--json : Keluaran JSON untuk monitor}';

    protected $description = 'Memeriksa kesehatan sistem Sudaya (hanya membaca)';

    public function handle(SystemHealth $health): int
    {
        $checks = $health->run();
        $worst = SystemHealth::worst($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => $worst, 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $worst === SystemHealth::FAIL ? self::FAILURE : self::SUCCESS;
        }

        $icon = [SystemHealth::OK => '<fg=green>✓</>', SystemHealth::WARN => '<fg=yellow>!</>', SystemHealth::FAIL => '<fg=red>✗</>'];
        $group = null;
        foreach ($checks as $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                $this->newLine();
                $this->line("<options=bold>{$group}</>");
            }
            $this->line("  {$icon[$check['status']]} {$check['label']}: {$check['message']}");
            if ($check['hint'] !== null) {
                $this->line("      <fg=gray>→ {$check['hint']}</>");
            }
        }

        $counts = array_count_values(array_column($checks, 'status'));
        $this->newLine();
        $summary = sprintf('%d OK, %d peringatan, %d gagal', $counts[SystemHealth::OK] ?? 0, $counts[SystemHealth::WARN] ?? 0, $counts[SystemHealth::FAIL] ?? 0);
        match ($worst) {
            SystemHealth::FAIL => $this->error("Ada yang harus diperbaiki: {$summary}"),
            SystemHealth::WARN => $this->warn("Berjalan, dengan catatan: {$summary}"),
            default => $this->info("Semua sehat: {$summary}"),
        };

        return $worst === SystemHealth::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
