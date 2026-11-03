<?php

// Satu operasi order/kas di proses PHP terpisah, untuk menguji request bersamaan
// (double click, retry jaringan, dua kasir) ke database sungguhan.
//   stdin: {"op": "create"|"pay"|"close"|"open"|"refund", "actorId"?: string, "input": {...},
//           "channel"?: string, "outlet"?: {...}, "startAt": unix float}
//   stdout: {"ok": true, "result": {...}, "late": bool} | {"ok": false, "reason", "message", "late": bool}

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Auth\AccessLoader;
use App\Services\Cash\CashSessions;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->isProduction()) {
    fwrite(STDERR, "Tidak boleh dijalankan di production.\n");
    exit(1);
}

$job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$actor = isset($job['actorId']) ? app(AccessLoader::class)->currentUser($job['actorId']) : null;
$context = new AuditContext('127.0.0.1', 'phpunit-concurrency');
// Koneksi dibuka sebelum menunggu agar semua proses mulai pada waktu yang sama.
DB::select('select 1');
$wait = (float) $job['startAt'] - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
// late = proses belum siap saat waktu mulai, jadi tidak benar-benar bersamaan.
$late = $wait <= 0;

try {
    $result = match ($job['op']) {
        'create' => app(OrderEngine::class)->create($job['channel'], $job['outlet'], $actor, $job['input'], $context),
        'pay' => app(OrderWorkflow::class)->confirmCash($actor, $job['input'], $context),
        'refund' => app(OrderWorkflow::class)->refundCash($actor, $job['input'], $context),
        'open' => app(CashSessions::class)->open($actor, $job['input'], $context),
        'close' => app(CashSessions::class)->close($actor, $job['input'], $context),
    };
    echo json_encode(['ok' => true, 'result' => $result, 'late' => $late, 'at' => microtime(true)]);
} catch (AccessException $e) {
    echo json_encode(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage(), 'late' => $late, 'at' => microtime(true)]);
}
