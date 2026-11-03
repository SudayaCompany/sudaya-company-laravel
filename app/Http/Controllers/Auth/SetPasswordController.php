<?php

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SupabaseAuth;
use App\Services\Auth\SupabaseAuthException;
use App\Services\Auth\SupabaseSession;
use App\Support\Access\RouteAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SetPasswordController
{
    private const MIN_LENGTH = 12;

    public function show(Request $request): Response
    {
        return Inertia::render('auth/set-password', ['fullName' => $request->user()->full_name]);
    }

    public function store(Request $request, SupabaseSession $session, SupabaseAuth $auth, AuditLogger $audit): RedirectResponse
    {
        $input = $request->validate([
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:128'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ], [
            'password.required' => 'Password minimal '.self::MIN_LENGTH.' karakter.',
            'password.min' => 'Password minimal '.self::MIN_LENGTH.' karakter.',
            'password_confirmation.required' => 'Konfirmasi password tidak sama.',
            'password_confirmation.same' => 'Konfirmasi password tidak sama.',
        ]);

        $accessToken = $session->accessToken($request);
        if ($accessToken === null) {
            throw ValidationException::withMessages(['form' => 'Sesi berakhir. Buka ulang link undangan.']);
        }

        try {
            $auth->updatePassword($accessToken, $input['password']);
        } catch (SupabaseAuthException $error) {
            throw ValidationException::withMessages(['form' => match ($error->errorCode) {
                'weak_password' => 'Password terlalu lemah. Gunakan kombinasi huruf, angka, dan simbol.',
                'same_password' => 'Gunakan password yang berbeda dari sebelumnya.',
                default => 'Gagal menyimpan password. Coba lagi.',
            }]);
        }

        $user = $request->user();
        $audit->record([
            'company_id' => $user->company_id,
            'actor_id' => $user->id,
            'action' => 'auth.password_set',
            'entity_type' => 'user',
            'entity_id' => $user->id,
        ], AuditContext::fromRequest($request));

        return redirect(RouteAccess::landing($user->grants()) ?? '/login');
    }
}
