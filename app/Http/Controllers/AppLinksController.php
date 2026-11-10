<?php

namespace App\Http\Controllers;

use App\Support\StaffApp;
use Illuminate\Http\JsonResponse;

/** Digital Asset Links untuk APK Android (Trusted Web Activity) aplikasi staf. */
class AppLinksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $links = StaffApp::assetLinks();
        abort_if($links === null, 404);

        return response()->json($links)->header('Cache-Control', 'public, max-age=3600');
    }
}
