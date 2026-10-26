<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permissions::AUDIT_VIEW);
    }
}
