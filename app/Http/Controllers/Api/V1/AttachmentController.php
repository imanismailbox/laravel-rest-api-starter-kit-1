<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attachment\CreateAttachment;
use App\Actions\Attachment\DeleteAttachment;
use App\Actions\Attachment\DownloadAttachment;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Attachment\UploadAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Liamsinami\ApiEssentials\ApiResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Attachment')]
final class AttachmentController extends ApiController
{
    /**
     * Upload an attachment (standalone or attached to a model, single or multiple files).
     */
    public function store(UploadAttachmentRequest $request, CreateAttachment $action): JsonResponse
    {
        $attachable = $request->resolveAttachable();
        $uploadedFiles = $request->uploadedFiles();
        $isPrivate = (bool) $request->validated('is_private', false);
        $userId = $request->user()?->id;

        if ($request->hasFile('files')) {
            $attachments = collect();

            foreach ($uploadedFiles as $file) {
                $attachments->push($action->handle(
                    file: $file,
                    description: $request->validated('description'),
                    isPrivate: $isPrivate,
                    userId: $userId,
                    attachable: $attachable,
                ));
            }

            return ApiResponse::created(
                data: AttachmentResource::collection($attachments),
                message: 'Attachments uploaded successfully.',
            );
        }

        $file = $uploadedFiles[0];

        $attachment = $action->handle(
            file: $file,
            name: $request->validated('name'),
            description: $request->validated('description'),
            isPrivate: $isPrivate,
            userId: $userId,
            attachable: $attachable,
        );

        return ApiResponse::created(
            data: new AttachmentResource($attachment),
            message: 'Attachment uploaded successfully.',
        );
    }

    /**
     * Get attachment details.
     */
    public function show(Attachment $attachment): JsonResponse
    {
        Gate::authorize('view', $attachment);

        return ApiResponse::success(
            data: new AttachmentResource($attachment),
            message: 'Attachment retrieved successfully.',
        );
    }

    /**
     * Download attachment file.
     */
    public function download(Attachment $attachment, DownloadAttachment $action): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        return $action->handle($attachment);
    }

    /**
     * Delete an attachment and its file from storage.
     */
    public function destroy(Attachment $attachment, DeleteAttachment $action): JsonResponse
    {
        Gate::authorize('delete', $attachment);

        $action->handle($attachment);

        return ApiResponse::success(
            message: 'Attachment deleted successfully.',
        );
    }
}
