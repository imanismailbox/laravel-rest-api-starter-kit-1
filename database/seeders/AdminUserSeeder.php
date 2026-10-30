<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        /** @var User $admin */
        $admin = User::query()->firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Administrator',
            'username' => 'admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        if ($admin->wasRecentlyCreated) {
            $admin->assignRole(UserRole::Admin->value);
        }
    }
}
