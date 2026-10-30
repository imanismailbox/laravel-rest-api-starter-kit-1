<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\User\CreateUser;
use App\Actions\User\DeleteUser;
use App\Actions\User\ImportUsers;
use App\Actions\User\UpdateUser;
use App\Exports\UserImportTemplateExport;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Attachment\SyncAttachmentsRequest;
use App\Http\Requests\Api\V1\BatchOperationRequest;
use App\Http\Requests\Api\V1\User\CreateUserRequest;
use App\Http\Requests\Api\V1\User\ImportUsersRequest;
use App\Http\Requests\Api\V1\User\UpdateUserRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\UserResource;
use App\Models\Attachment;
use App\Models\User;
use App\Query\Definitions\UserQueryDefinition;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

#[Group('User')]
final class UserController extends ApiController
{
    protected string $model = User::class;

    protected string $resource = UserResource::class;

    /**
     * Display a paginated/filtered list of users or export to spreadsheet.
     */
    public function index(Request $request): Response
    {
        return $this->handleIndex(UserQueryDefinition::class, $request);
    }

    /**
     * Store a newly created user.
     */
    public function store(CreateUserRequest $request, CreateUser $action): JsonResponse
    {
        $user = $action->handle($request->validated());
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::created(
            data: new UserResource($user),
            message: 'User created successfully.',
        );
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User retrieved successfully.',
        );
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): JsonResponse
    {
        $updated = $action->handle($user, $request->validated());
        $updated->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($updated),
            message: 'User updated successfully.',
        );
    }

    /**
     * Remove the specified user (Soft delete).
     */
    public function destroy(User $user, DeleteUser $action): JsonResponse
    {
        $action->handle($user);

        return ApiResponse::success(
            message: 'User deleted successfully.',
        );
    }

    /**
     * Batch delete multiple users (Soft delete).
     */
    public function batchDestroy(BatchOperationRequest $request): JsonResponse
    {
        return $this->batchDestroyInternal($request->ids());
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(string $id): JsonResponse
    {
        /** @var User $user */
        $user = User::onlyTrashed()->findOrFail($id);
        Gate::authorize('manage-user', $user);
        $user->restore();
        $user->loadMissing(['roles', 'permissions']);

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User restored successfully.',
        );
    }

    /**
     * Permanently delete a user from database.
     */
    public function forceDestroy(string $id): JsonResponse
    {
        /** @var User $user */
        $user = User::withTrashed()->findOrFail($id);
        Gate::authorize('manage-user', $user);
        DB::transaction(fn () => $user->forceDelete());

        return ApiResponse::success(
            message: 'User permanently deleted.',
        );
    }

    /**
     * Batch restore soft-deleted users.
     */
    public function batchRestore(BatchOperationRequest $request): JsonResponse
    {
        return $this->batchRestoreInternal($request->ids());
    }

    /**
     * Batch permanently delete users from database.
     */
    public function batchForceDestroy(BatchOperationRequest $request): JsonResponse
    {
        return $this->batchForceDestroyInternal($request->ids());
    }

    /**
     * Bulk import users from a spreadsheet file (.xlsx, .xls, .csv).
     */
    public function import(ImportUsersRequest $request, ImportUsers $action): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $result = $action->handle($file);

        return ApiResponse::success(
            data: $result,
            message: 'Bulk user import completed.',
        );
    }

    /**
     * Download sample spreadsheet template for bulk user import.
     */
    public function template(): BinaryFileResponse|Response
    {
        return Excel::download(new UserImportTemplateExport, 'users_import_template.xlsx');
    }

    /**
     * Link/sync attachments to a user.
     */
    public function syncAttachments(SyncAttachmentsRequest $request, User $user): JsonResponse
    {
        $attachments = Attachment::query()->whereIn('id', $request->attachmentIds())->get();
        foreach ($attachments as $attachment) {
            Gate::authorize('view', $attachment);
        }

        $user->syncAttachments($request->attachmentIds());
        $user->load('attachments');

        return ApiResponse::success(
            data: AttachmentResource::collection($user->attachments),
            message: 'User attachments synchronized successfully.',
        );
    }

    /**
     * Detach an attachment from a user.
     */
    public function detachAttachment(User $user, Attachment $attachment): JsonResponse
    {
        Gate::authorize('updateAttachments', $user);
        Gate::authorize('view', $attachment);
        abort_unless($user->attachments()->whereKey($attachment->id)->exists(), 404);

        $user->detachAttachment($attachment);

        return ApiResponse::success(
            message: 'Attachment unlinked from user successfully.',
        );
    }

    /**
     * Stop delegates from batch-changing administrators.
     */
    protected function authorizeBatchItem(Model $item): void
    {
        Gate::authorize('manage-user', $item);
    }
}
