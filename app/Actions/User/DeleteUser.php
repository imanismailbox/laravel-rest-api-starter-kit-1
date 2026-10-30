<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final readonly class DeleteUser
{
    /**
     * Delete the given user.
     */
    public function handle(User $user): bool
    {
        Gate::authorize('manage-user', $user);

        return (bool) $user->delete();
    }
}
