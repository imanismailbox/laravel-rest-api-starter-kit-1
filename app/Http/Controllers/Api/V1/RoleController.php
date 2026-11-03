<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Role\CreateRole;
use App\Actions\Role\DeleteRole;
use App\Actions\Role\UpdateRole;
use App\Enums\UserRole;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Role\CreateRoleRequest;
use App\Http\Requests\Api\V1\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Query\Definitions\RoleQueryDefinition;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liamsinami\ApiEssentials\ApiResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

#[Group('Role & Permission')]
final class RoleController extends ApiController
{
    protected string $model = Role::class;

    protected string $resource = RoleResource::class;

    /**
     * Display a list of roles.
     */
    public function index(Request $request): Response
    {
        return $this->handleIndex(RoleQueryDefinition::class, $request);
    }

    /**
     * Store a newly created role.
     */
    public function store(CreateRoleRequest $request, CreateRole $action): JsonResponse
    {
        $role = $action->handle($request->validated());
        $role->loadMissing('permissions');

        return ApiResponse::created(
            data: new RoleResource($role),
            message: 'Role created successfully.',
        );
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role): JsonResponse
    {
        $role->loadMissing('permissions');

        return ApiResponse::success(
            data: new RoleResource($role),
            message: 'Role retrieved successfully.',
        );
    }

    /**
     * Update the specified role.
     */
    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $action): JsonResponse
    {
        if (UserRole::isImmutable($role->name)) {
            return ApiResponse::forbidden('This role is immutable and cannot be modified.');
        }

        $updated = $action->handle($role, $request->validated());
        $updated->loadMissing('permissions');

        return ApiResponse::success(
            data: new RoleResource($updated),
            message: 'Role updated successfully.',
        );
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role, DeleteRole $action): JsonResponse
    {
        if (UserRole::isImmutable($role->name)) {
            return ApiResponse::forbidden('This role is immutable and cannot be deleted.');
        }

        $action->handle($role);

        return ApiResponse::success(
            message: 'Role deleted successfully.',
        );
    }
}
