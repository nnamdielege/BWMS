<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::LOCATIONS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::LOCATIONS_MANAGE);
    }

    public function update(User $user): bool
    {
        return $user->can(Permissions::LOCATIONS_MANAGE);
    }
}
