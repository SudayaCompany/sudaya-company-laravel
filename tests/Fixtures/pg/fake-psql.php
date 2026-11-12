<?php

// psql palsu: mencatat argumen + SQL yang diterima ke FAKE_PSQL_LOG. FAKE_PG_MODE=psqlfail → gagal.
$sql = stream_get_contents(STDIN);
file_put_contents((string) getenv('FAKE_PSQL_LOG'), implode(' ', array_slice($argv, 1))."\n---\n".$sql);
if (getenv('FAKE_PG_MODE') === 'psqlfail') {
    fwrite(STDERR, 'psql: error: insert violates constraint; password '.getenv('PGPASSWORD')."\n");
    exit(3);
}
exit(0);
