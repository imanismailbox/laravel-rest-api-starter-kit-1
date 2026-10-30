<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

final readonly class UpdateUser
{
    /**
     * Update user details and roles.
     *
     * @param  array{name?: string, email?: string, password?: string, roles?: array<int, string>}  $data
     */
    public function handle(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            Gate::authorize('manage-user', $user);

            if (in_array(UserRole::Admin->value, $data['roles'] ?? [], true)) {
                Gate::authorize('assign-admin-role');
            }

            if (isset($data['name'])) {
                $user->name = $data['name'];
            }

            if (isset($data['email'])) {
                $user->email = $data['email'];
            }

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            return $user;
        });
    }
}
