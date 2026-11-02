<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class UserPolicy
{
    public function updateAttachments(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->hasRole(UserRole::Admin->value);
    }
}
