<?php

declare(strict_types=1);

namespace App\Actions\Role;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class CreateRole
{
    /**
     * Create a new role and optionally sync permissions.
     *
     * @param  array{name: string, guard_name?: ?string, permissions?: array<int, string>}  $data
     */
    public function handle(array $data): Role
    {
        Gate::authorize('grant-permissions', [$data['permissions'] ?? []]);

        return DB::transaction(function () use ($data): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'name' => $data['name'],
                'guard_name' => $data['guard_name'] ?? 'web',
            ]);

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }
}
