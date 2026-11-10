<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Attendance\OutsideDuty;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Foto bukti tugas luar dari bucket privat, hanya untuk peninjau outlet; tidak di-cache. */
class OutsideDutyController
{
    public function __construct(private OutsideDuty $duties) {}

    public function evidence(Request $request, string $duty): SymfonyResponse
    {
        try {
            $photo = $this->duties->evidence($request->user(), $duty);
        } catch (AccessException $error) {
            abort($error->reason === 'forbidden' ? 403 : 404);
        }

        return response($photo['bytes'], 200, [
            'Content-Type' => $photo['contentType'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
