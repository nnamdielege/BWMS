<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\User;

class UserInvitationPolicy
{
    public function create(User $actor): bool
    {
        return $actor->can(Permissions::USERS_INVITE);
    }

    /** Only admins may invite someone straight into the admin role. */
    public function inviteWithRole(User $actor, string $role): bool
    {
        return $role !== Permissions::ROLE_ADMIN || $actor->hasRole(Permissions::ROLE_ADMIN);
    }
}
