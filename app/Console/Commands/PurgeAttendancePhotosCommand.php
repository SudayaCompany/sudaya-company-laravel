<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendancePhotos;
use App\Services\Attendance\AttendancePhotoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retensi foto absen (keputusan owner 9 November, G5): foto selfie dan foto bukti tugas luar
 * dihapus dari Storage setelah 90 hari; jam, lokasi, jarak, metode absen, dan data pengajuan
 * tetap tersimpan. Dijadwalkan
 * harian di routes/console.php (butuh cron `schedule:run` di server).
 */
class PurgeAttendancePhotosCommand extends Command
{
    protected $signature = 'sudaya:purge-attendance-photos {--days= : Umur foto (hari), default kebijakan 90}';

    protected $description = 'Menghapus foto selfie absen dan bukti tugas luar yang melewati masa retensi';

    public function handle(AttendancePhotoStorage $photos): int
    {
        $days = (int) ($this->option('days') ?: AttendancePhotos::RETENTION_DAYS);
        if ($days < 1) {
            $this->error('Jumlah hari minimal 1.');

            return self::FAILURE;
        }
        $total = 0;
        do {
            $rows = DB::table('attendances')
                ->where('check_in_at', '<', DB::raw("now() - make_interval(days => {$days})"))
                ->where(fn ($q) => $q->whereNotNull('check_in_photo_path')->orWhereNotNull('check_out_photo_path'))
                ->orderBy('check_in_at')
                ->limit(100)
                ->get(['id', 'check_in_photo_path', 'check_out_photo_path']);
            if ($rows->isEmpty()) {
                break;
            }
            $paths = $rows->flatMap(fn ($r) => [$r->check_in_photo_path, $r->check_out_photo_path])->filter()->values()->all();
            // Hapus file dulu; path baru dikosongkan bila Storage berhasil, jadi tidak ada foto yatim tanpa jejak.
            $photos->remove($paths);
            DB::table('attendances')->whereIn('id', $rows->pluck('id')->all())->update([
                'check_in_photo_path' => null,
                'check_out_photo_path' => null,
                'updated_at' => DB::raw('now()'),
            ]);
            $total += count($paths);
        } while ($rows->count() === 100);

        // Bukti tugas luar: umur dihitung dari akhir jendela tugas.
        $evidence = 0;
        do {
            $rows = DB::table('outside_duty_requests')
                ->where('ends_at', '<', DB::raw("now() - make_interval(days => {$days})"))
                ->whereNotNull('evidence_path')
                ->orderBy('ends_at')
                ->limit(100)
                ->get(['id', 'evidence_path']);
            if ($rows->isEmpty()) {
                break;
            }
            $photos->remove($rows->pluck('evidence_path')->all());
            DB::table('outside_duty_requests')->whereIn('id', $rows->pluck('id')->all())->update(['evidence_path' => null, 'updated_at' => DB::raw('now()')]);
            $evidence += $rows->count();
        } while ($rows->count() === 100);

        $this->info("Foto absen dihapus: {$total}; bukti tugas luar dihapus: {$evidence} (lebih dari {$days} hari).");

        return self::SUCCESS;
    }
}
