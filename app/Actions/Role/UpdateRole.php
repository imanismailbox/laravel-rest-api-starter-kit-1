<?php

declare(strict_types=1);

namespace App\Actions\Role;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class UpdateRole
{
    /**
     * Update an existing role and optionally sync permissions.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Role $role, array $data): Role
    {
        Gate::authorize('grant-permissions', [$data['permissions'] ?? []]);

        return DB::transaction(function () use ($role, $data): Role {
            $fillable = array_intersect_key($data, array_flip(['name', 'guard_name']));
            if ($fillable !== []) {
                $role->update($fillable);
            }

            if (array_key_exists('permissions', $data) && is_array($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }
}
