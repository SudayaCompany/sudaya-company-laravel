<?php

namespace Tests\Live;

/**
 * Menjalankan beberapa operasi bersamaan di proses PHP terpisah (tests/Live/bin/order-op.php).
 * Semua proses membuka koneksi DB dulu lalu menunggu waktu mulai yang sama.
 */
trait RunsConcurrently
{
    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    protected function concurrently(array $jobs): array
    {
        $startAt = microtime(true) + 12;
        $procs = [];
        foreach ($jobs as $job) {
            $proc = proc_open([PHP_BINARY, base_path('tests/Live/bin/order-op.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            fwrite($pipes[0], json_encode([...$job, 'startAt' => $startAt]));
            fclose($pipes[0]);
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, "proses gagal: {$out} {$err}");
            $results[] = $decoded;
        }
        $this->assertSame(array_fill(0, count($jobs), false), array_column($results, 'late'), 'semua proses harus siap sebelum waktu mulai bersama');

        return $results;
    }
}
