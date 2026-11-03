<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\AccessRules;
use App\Support\Access\AccessScope;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /** Anti privilege escalation: target tidak boleh punya akses yang tidak dimiliki actor. */
    public function dominate(User $actor, User $target, AccessScope $scope): Response
    {
        return AccessRules::dominates($actor->grants(), $target->grants(), $scope)
            ? Response::allow()
            : Response::deny('Akun ini memiliki akses yang lebih luas dari akses Anda.');
    }
}
