<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Untuk uptime check. Tanpa detail versi, konfigurasi, atau alasan kegagalan. */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');

            return $this->respond('ok', 200);
        } catch (Throwable $error) {
            Log::error('health', ['exception' => $error::class]);

            return $this->respond('error', 503);
        }
    }

    private function respond(string $status, int $code): JsonResponse
    {
        return response()->json(['status' => $status], $code)->header('Cache-Control', 'no-store');
    }
}
