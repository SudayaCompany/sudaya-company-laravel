<?php

return [
    // Header yang ditimpa reverse proxy tepercaya, mis. X-Real-IP di Nginx.
    // Kosong berarti IP client tidak diketahui, dan rate limit hanya per email.
    'trusted_ip_header' => env('TRUSTED_IP_HEADER'),

    // Folder hasil backup harian database (deploy/backup-db.sh). Dipakai `sudaya:doctor`
    // untuk memastikan backup terbaru belum lewat 26 jam. Kosong = backup belum diatur.
    'backup_dir' => env('BACKUP_DIR'),

    // Program pg_dump/pg_restore (versi mayor ≥ server Supabase, kini 17). Isi path lengkap
    // bila bukan yang ada di PATH, mis. /usr/lib/postgresql/17/bin/pg_dump.
    'pg_dump' => env('PG_DUMP_BINARY', 'pg_dump'),
    'pg_restore' => env('PG_RESTORE_BINARY', 'pg_restore'),
    'psql' => env('PSQL_BINARY', 'psql'),
];
