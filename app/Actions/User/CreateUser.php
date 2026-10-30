<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

final readonly class CreateUser
{
    /**
     * Create a new user.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): User
    {
        if (in_array(UserRole::Admin->value, $data['roles'] ?? [], true)) {
            Gate::authorize('assign-admin-role');
        }

        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'password' => Hash::make((string) $data['password']),
                'email_verified_at' => ($data['email_verified'] ?? true) ? now() : null,
            ]);

            if (! empty($data['roles']) && is_array($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            return $user;
        });
    }
}
