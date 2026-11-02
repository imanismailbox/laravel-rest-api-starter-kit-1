<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\User;

final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->id === $attachment->getAttribute('user_id') || $user->hasRole(UserRole::Admin->value);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }
}
