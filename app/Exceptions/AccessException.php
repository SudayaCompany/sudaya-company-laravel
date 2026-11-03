<?php

namespace App\Exceptions;

use RuntimeException;

/** Error yang aman ditampilkan ke user (pesan tanpa detail internal). */
final class AccessException extends RuntimeException
{
    /** @param 'unauthenticated'|'forbidden'|'not_found'|'invalid'|'conflict' $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'Anda tidak memiliki izin untuk tindakan ini.'): self
    {
        return new self('forbidden', $message);
    }

    public static function notFound(): self
    {
        return new self('not_found', 'Data tidak ditemukan.');
    }
}
