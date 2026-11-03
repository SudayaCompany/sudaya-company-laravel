<?php

namespace App\Http\Controllers\Admin;

use App\Services\Auth\AccessAdmin;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController
{
    public function __invoke(Request $request, AccessAdmin $admin): Response
    {
        return Inertia::render('admin/audit', ['logs' => $admin->listAuditLogs($request->user(), 100)]);
    }
}
