<?php

// Satu percobaan login (reservasi rate limit) dalam proses PHP terpisah, untuk test burst paralel.

use App\Services\Security\LoginLimits;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo $app->make(LoginLimits::class)->begin($argv[1], null)['ok'] ? 'ok' : 'blocked';
