<?php

namespace App\Services\Auth;

use RuntimeException;

/** Kegagalan dari Supabase Auth. `errorCode` mengikuti `error_code` GoTrue. */
final class SupabaseAuthException extends RuntimeException
{
    public function __construct(public readonly ?string $errorCode, public readonly ?int $status = null)
    {
        parent::__construct('Supabase Auth: '.($errorCode ?? 'unavailable').($status ? " ({$status})" : ''));
    }

    /** Jaringan putus atau Supabase bermasalah: sesi user belum tentu tidak valid. */
    public function isTransient(): bool
    {
        return $this->status === null || $this->status >= 500 || $this->status === 429;
    }
}
