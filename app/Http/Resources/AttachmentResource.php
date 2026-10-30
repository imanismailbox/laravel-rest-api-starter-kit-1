<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin Attachment
 */
final class AttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, user_id: ?string, name: string, description: ?string, path: string, disk: string, extension: string, mime: string, size: int, human_size: string, type: string, is_image: bool, is_video: bool, is_audio: bool, is_document: bool, is_private: bool, url: string, download_url: string, created_at: ?string, updated_at: ?string}
     */
    public function toArray(Request $request): array
    {
        Gate::authorize('view', $this->resource);

        return [
            'id' => (int) $this->id,
            'user_id' => $this->user_id ? (string) $this->user_id : null,
            'name' => $this->name,
            'description' => $this->description,
            'path' => $this->path,
            'disk' => $this->disk,
            'extension' => $this->extension(),
            'mime' => $this->mime,
            'size' => (int) $this->size,
            'human_size' => $this->humanSize(),
            'type' => $this->type(),
            'is_image' => $this->isImage(),
            'is_video' => $this->isVideo(),
            'is_audio' => $this->isAudio(),
            'is_document' => $this->isDocument(),
            'is_private' => (bool) $this->is_private,
            'url' => $this->url(),
            'download_url' => $this->downloadUrl(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
