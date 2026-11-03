<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Services\Auth\InvalidTokenException;
use App\Services\Auth\SupabaseAuth;
use App\Services\Auth\SupabaseAuthException;
use App\Services\Auth\SupabaseSession;
use App\Services\Auth\SupabaseTokens;
use App\Services\Security\LoginLimits;
use App\Support\Access\RouteAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController
{
    private const NOTICES = [
        'link' => 'Link undangan tidak valid atau sudah kedaluwarsa. Minta admin membuat undangan baru.',
    ];

    public function __construct(
        private SupabaseAuth $auth,
        private SupabaseSession $session,
        private LoginLimits $limits,
        private AccessLoader $access,
        private AuditLogger $audit,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $next = $request->query('next');
        $user = $request->user();

        if ($user !== null) {
            $destination = $user->isStaffActive() ? RouteAccess::postLogin($user->grants(), $next) : null;
            if ($destination !== null) {
                return redirect($destination);
            }

            // Sudah login tapi tidak bisa masuk: tampilkan alasan, jangan redirect (mencegah loop).
            return Inertia::render('auth/login', ['reason' => $this->refusal($user)]);
        }

        return Inertia::render('auth/login', [
            'next' => RouteAccess::safeNext($next),
            'notice' => self::NOTICES[$request->query('error')] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string', 'max:128'],
            'next' => ['nullable', 'string', 'max:512'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ]);
        $email = strtolower($input['email']);

        $context = AuditContext::fromRequest($request);
        $attempt = $this->limits->begin($email, $context->ip);
        if (! $attempt['ok']) {
            $this->fail(LoginLimits::message($attempt['retryAfterSeconds']));
        }

        try {
            ['tokens' => $tokens, 'userId' => $userId] = $this->auth->signInWithPassword($email, $input['password']);
        } catch (SupabaseAuthException $error) {
            if ($error->errorCode === 'invalid_credentials') {
                $this->limits->recordFailure($email, $attempt, $context);
            } else {
                $this->limits->release($attempt);
            }
            $this->fail($this->authErrorMessage($error->errorCode));
        }
        $this->limits->release($attempt);

        // Akun Supabase Auth valid, tapi wajib terdaftar dan aktif sebagai staf.
        $user = $this->access->currentUser($userId);
        $destination = $user?->isStaffActive() ? RouteAccess::postLogin($user->grants(), $input['next'] ?? null) : null;
        if ($user === null || $destination === null) {
            $this->abandon($tokens);
            $this->fail($this->refusal($user));
        }

        try {
            $this->session->start($request, $user, $tokens);
        } catch (InvalidTokenException) {
            $this->fail($this->authErrorMessage(null));
        }

        $this->audit->record([
            'company_id' => $user->company_id,
            'actor_id' => $user->id,
            'action' => 'auth.login',
            'entity_type' => 'user',
            'entity_id' => $user->id,
        ], $context);
        $this->limits->rememberNetwork($email, $context->ip);

        return redirect($destination);
    }

    private function refusal(?User $user): string
    {
        if ($user === null) {
            return 'Akun ini belum terdaftar sebagai staf Sudaya.';
        }

        return $user->isStaffActive()
            ? 'Akun Anda belum memiliki akses. Hubungi admin.'
            : 'Akun Anda dinonaktifkan. Hubungi admin.';
    }

    private function authErrorMessage(?string $code): string
    {
        return match ($code) {
            'invalid_credentials' => 'Email atau password salah.',
            'email_not_confirmed' => 'Email belum dikonfirmasi. Buka link undangan yang Anda terima.',
            'user_banned' => 'Akun Anda dinonaktifkan. Hubungi admin.',
            'over_request_rate_limit' => 'Terlalu banyak percobaan login. Tunggu beberapa menit lalu coba lagi.',
            default => 'Login gagal. Periksa koneksi lalu coba lagi.',
        };
    }

    /** Session Supabase yang baru dibuat ditutup lagi karena akun tidak boleh masuk. */
    private function abandon(SupabaseTokens $tokens): void
    {
        try {
            $this->auth->signOut($tokens->accessToken);
        } catch (SupabaseAuthException) {
            // Token tidak disimpan di mana pun, jadi session ini tidak bisa dipakai lewat aplikasi.
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['form' => $message]);
    }
}
