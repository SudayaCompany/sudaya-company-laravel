<?php

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SupabaseSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class LogoutController
{
    public function __invoke(Request $request, SupabaseSession $session, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        if ($user !== null) {
            $audit->record([
                'company_id' => $user->company_id,
                'actor_id' => $user->id,
                'action' => 'auth.logout',
                'entity_type' => 'user',
                'entity_id' => $user->id,
            ], AuditContext::fromRequest($request));
        }

        $session->end($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Perangkat outlet dipakai bergantian: halaman lama tidak boleh terbuka lewat tombol Back.
        Inertia::clearHistory();

        return redirect('/login');
    }
}
