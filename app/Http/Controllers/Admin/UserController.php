<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Auth\AccessAdmin;
use App\Services\Auth\AuthAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Input divalidasi ulang di AccessAdmin, dan actor selalu diambil dari sesi, bukan dari body. */
class UserController
{
    public function __construct(private AccessAdmin $admin) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('admin/users', [
            'users' => $this->admin->listCompanyUsers($actor),
            'roles' => $this->admin->listCompanyRoles($actor),
            'outlets' => $this->admin->listCompanyOutlets($actor),
        ]);
    }

    public function invite(Request $request, AuthAdmin $authAdmin): RedirectResponse
    {
        $result = $this->admin->inviteStaff($request->user(), $request->all(), AuditContext::fromRequest($request), $authAdmin);

        if ($result['status'] === 'linked') {
            return $this->done('Akun sudah terdaftar di sistem login dan langsung dihubungkan. Staf bisa login dengan password akunnya.');
        }
        Inertia::flash('link', $this->confirmUrl($result['hashedToken'], 'invite'));

        return $this->done('Undangan dibuat.');
    }

    public function assignRole(Request $request): RedirectResponse
    {
        $this->admin->assignRole($request->user(), $request->all(), AuditContext::fromRequest($request));

        return $this->done('Peran ditambahkan.');
    }

    public function revokeRole(Request $request): RedirectResponse
    {
        $this->admin->revokeRole($request->user(), $request->all(), AuditContext::fromRequest($request));

        return $this->done('Peran dicabut.');
    }

    public function setActive(Request $request, AuthAdmin $authAdmin): RedirectResponse
    {
        $result = $this->admin->setUserActive($request->user(), $request->all(), AuditContext::fromRequest($request), $authAdmin);

        return $this->done($result['warning'] ?? 'Status akun diperbarui.');
    }

    public function recovery(Request $request, AuthAdmin $authAdmin): RedirectResponse
    {
        $hashedToken = $this->admin->createRecoveryLink($request->user(), $request->all(), AuditContext::fromRequest($request), $authAdmin);
        Inertia::flash('link', $this->confirmUrl($hashedToken, 'recovery'));

        return $this->done('Link reset password dibuat.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return back();
    }

    /** Dari APP_URL, bukan header Host, supaya link tidak bisa diarahkan ke domain lain. */
    private function confirmUrl(string $hashedToken, string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/confirm?'.http_build_query([
            'token_hash' => $hashedToken,
            'type' => $type,
            'next' => '/auth/set-password',
        ]);
    }
}
