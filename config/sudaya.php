<?php

return [
    // Header yang ditimpa reverse proxy tepercaya, mis. X-Real-IP di Nginx.
    // Kosong berarti IP client tidak diketahui, dan rate limit hanya per email.
    'trusted_ip_header' => env('TRUSTED_IP_HEADER'),

    // Folder hasil backup harian database (deploy/backup-db.sh). Dipakai `sudaya:doctor`
    // untuk memastikan backup terbaru belum lewat 26 jam. Kosong = backup belum diatur.
    'backup_dir' => env('BACKUP_DIR'),
];
