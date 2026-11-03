<?php

return [
    // Header yang ditimpa reverse proxy tepercaya, mis. X-Real-IP di Nginx.
    // Kosong berarti IP client tidak diketahui, dan rate limit hanya per email.
    'trusted_ip_header' => env('TRUSTED_IP_HEADER'),
];
