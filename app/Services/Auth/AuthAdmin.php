<?php

namespace App\Services\Auth;

/** Operasi Supabase Auth Admin, terikat lewat container agar bisa diganti saat test. */
interface AuthAdmin
{
    /** @return array{userId: string, hashedToken: string} */
    public function createInvite(string $email, string $fullName): array;

    /** @return string token hash untuk link reset password */
    public function createRecovery(string $email): string;

    public function setBanned(string $userId, bool $banned): void;
}
