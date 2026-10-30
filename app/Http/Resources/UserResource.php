<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: string, name: string, email: string, username: ?string, avatar: ?string, avatar_url: ?string, email_verified_at: ?string, roles?: array<int, string>, permissions?: array<int, string>, created_at: ?string, updated_at: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->whenHas('id', fn (): string => (string) $this->id),
            'name' => $this->whenHas('name'),
            'email' => $this->whenHas('email'),
            'username' => $this->whenHas('username'),
            'avatar' => $this->whenHas('avatar'),
            'avatar_url' => $this->whenHas('avatar', fn (): ?string => $this->avatarUrl()),
            'email_verified_at' => $this->whenHas('email_verified_at', fn (): ?string => $this->email_verified_at?->toISOString()),
            'roles' => $this->when(
                $this->relationLoaded('roles'),
                fn (): array => $this->getRoleNames()->values()->toArray()
            ),
            'permissions' => $this->when(
                $this->relationLoaded('permissions') || ($this->relationLoaded('roles') && $this->roles->every(fn ($role): bool => $role->relationLoaded('permissions'))),
                fn (): array => $this->getAllPermissions()->pluck('name')->values()->toArray()
            ),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->whenHas('created_at', fn (): ?string => $this->created_at?->toISOString()),
            'updated_at' => $this->whenHas('updated_at', fn (): ?string => $this->updated_at?->toISOString()),
        ];
    }
}
