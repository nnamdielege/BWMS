<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PRODUCTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::PRODUCTS_MANAGE);
    }

    public function update(User $user): bool
    {
        return $user->can(Permissions::PRODUCTS_MANAGE);
    }
}
