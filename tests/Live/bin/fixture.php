<?php

// Fixture untuk QA browser, di luar PHPUnit.
//   php tests/Live/bin/fixture.php create <file-json>   membuat company dan akun test
//   php tests/Live/bin/fixture.php cleanup <runId>      menghapus semua data run itu
// File JSON berisi password test: simpan di luar repo dan hapus setelah dipakai.

use Illuminate\Contracts\Console\Kernel;
use Tests\Live\LiveContext;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->isProduction()) {
    fwrite(STDERR, "Tidak boleh dijalankan di production.\n");
    exit(1);
}

[$command, $argument] = [$argv[1] ?? '', $argv[2] ?? ''];

if ($command === 'create' && $argument !== '') {
    $ctx = new LiveContext;
    $company = $ctx->createCompany('qa');
    $users = [];
    foreach ([
        'superAdmin' => [['role' => 'super_admin'], 'QA Super Admin'],
        'owner' => [['role' => 'owner'], 'QA Owner'],
        'barista' => [['role' => 'barista', 'outlet' => 'outlet'], 'QA Barista'],
        'cashier' => [['role' => 'cashier', 'outlet' => 'outlet'], 'QA Kasir'],
    ] as $key => [$assignment, $name]) {
        $users[$key] = $ctx->createUser($company, [$assignment], true, $name);
    }
    file_put_contents($argument, json_encode(['runId' => $ctx->runId, 'company' => $company, 'users' => $users], JSON_PRETTY_PRINT));
    echo $ctx->runId, "\n";
    exit(0);
}

if ($command === 'cleanup' && $argument !== '') {
    (new LiveContext($argument))->cleanup();
    echo "bersih {$argument}\n";
    exit(0);
}

fwrite(STDERR, "Pakai: create <file-json> | cleanup <runId>\n");
exit(1);
