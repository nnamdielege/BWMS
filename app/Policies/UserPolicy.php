<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permissions::USERS_VIEW);
    }

    /**
     * Role changes: never on yourself, and only an admin may touch an admin
     * account or grant the admin role. The roles being granted are checked
     * again in the controller via canGrantRoles().
     */
    public function updateRoles(User $actor, User $target): bool
    {
        if ($actor->is($target) || ! $actor->can(Permissions::USERS_MANAGE)) {
            return false;
        }

        return ! $target->hasRole(Permissions::ROLE_ADMIN) || $actor->hasRole(Permissions::ROLE_ADMIN);
    }

    /** @param  list<string>  $roles */
    public function canGrantRoles(User $actor, User $target, array $roles): bool
    {
        return ! in_array(Permissions::ROLE_ADMIN, $roles, true) || $actor->hasRole(Permissions::ROLE_ADMIN);
    }

    public function setActive(User $actor, User $target): bool
    {
        return $this->updateRoles($actor, $target);
    }
}
