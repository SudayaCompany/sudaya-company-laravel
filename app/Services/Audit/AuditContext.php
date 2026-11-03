<?php

namespace App\Services\Audit;

use App\Services\Security\ClientIp;
use Illuminate\Http\Request;

/** Konteks request untuk audit. Jangan pernah isi dengan token atau cookie. */
final readonly class AuditContext
{
    public function __construct(public ?string $ip = null, public ?string $userAgent = null) {}

    public static function fromRequest(Request $request): self
    {
        return new self(ClientIp::trusted($request), $request->userAgent());
    }
}
