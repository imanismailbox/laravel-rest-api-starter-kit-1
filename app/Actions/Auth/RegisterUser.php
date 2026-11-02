<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class RegisterUser
{
    /**
     * Handle user registration.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'username' => isset($data['username']) && $data['username'] !== '' ? (string) $data['username'] : null,
                'password' => Hash::make((string) $data['password']),
            ]);

            if (isset($data['role'])) {
                $user->assignRole((string) $data['role']);
            }

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
