<?php

// Satu checkout di proses PHP terpisah, untuk menguji request bersamaan (double click,
// retry jaringan) ke order engine sungguhan. Dipanggil oleh tests/Live/OrderEngineTest.
//   stdin: {"channel", "outlet": {"code"|"id"}, "actorId"?: string, "checkout", "startAt": unix float}
//   stdout: {"ok": true, "orderId", "replayed"} | {"ok": false, "reason", "message"}

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Auth\AccessLoader;
use App\Services\Orders\OrderEngine;
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
// Koneksi dibuka sebelum menunggu agar semua proses mulai insert pada waktu yang sama.
DB::select('select 1');
$wait = (float) $job['startAt'] - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}
// late = proses belum siap saat waktu mulai, jadi tidak benar-benar bersamaan.
$late = $wait <= 0;

try {
    $created = app(OrderEngine::class)->create($job['channel'], $job['outlet'], $actor, $job['checkout'], new AuditContext('127.0.0.1', 'phpunit-concurrency'));
    echo json_encode(['ok' => true, 'orderId' => $created['orderId'], 'replayed' => $created['replayed'], 'late' => $late]);
} catch (AccessException $e) {
    echo json_encode(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage(), 'late' => $late]);
}
