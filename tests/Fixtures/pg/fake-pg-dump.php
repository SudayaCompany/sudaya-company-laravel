<?php

// pg_dump palsu untuk unit test DatabaseBackup. FAKE_PG_MODE: ok | fail | corrupt.
$mode = getenv('FAKE_PG_MODE') ?: 'ok';
if ($mode === 'fail') {
    fwrite(STDERR, 'pg_dump: error: connection to server failed: password authentication failed for '.getenv('PGPASSWORD')."\n");
    exit(1);
}
$args = implode(' ', array_slice($argv, 1));
fwrite(STDOUT, ($mode === 'corrupt' ? 'XXXXX' : 'PGDMP').'|'.$args.'|'.getenv('PGHOST').'|'.str_repeat('x', 2048));
exit(0);
