<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\Attachment\CreateAttachment;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Trait HasAttachment
 *
 * Adds many-to-many polymorphic attachments support to any Eloquent model.
 *
 * @mixin Model
 */
trait HasAttachment
{
    /**
     * Boot the trait and register deleting event to detach associations.
     */
    public static function bootHasAttachment(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->morphToMany(Attachment::class, 'attachable', 'attachables')->detach();
        });
    }

    /**
     * Relationship to all attachments via attachables pivot table.
     *
     * @return MorphToMany<Attachment, $this>
     */
    public function attachments(): MorphToMany
    {
        return $this->morphToMany(Attachment::class, 'attachable', 'attachables');
    }

    /**
     * Get the first attachment.
     */
    public function attachment(): ?Attachment
    {
        if ($this->relationLoaded('attachments')) {
            /** @var Attachment|null $found */
            $found = $this->attachments->first();

            return $found;
        }

        /** @var Attachment|null $found */
        $found = $this->attachments()->first();

        return $found;
    }

    /**
     * Attach an attachment by model instance or ID.
     */
    public function attachAttachment(int|string|Attachment $attachment): void
    {
        $id = $attachment instanceof Attachment ? $attachment->id : $attachment;
        $this->attachments()->syncWithoutDetaching([$id]);
    }

    /**
     * Attach multiple attachments.
     *
     * @param  array<int, int|string|Attachment>  $attachments
     */
    public function attachAttachments(array $attachments): void
    {
        $ids = array_map(
            fn (int|string|Attachment $item): int|string => $item instanceof Attachment ? $item->id : $item,
            $attachments
        );

        $this->attachments()->syncWithoutDetaching($ids);
    }

    /**
     * Detach a specific attachment or all attachments.
     */
    public function detachAttachment(int|string|Attachment $attachment): void
    {
        $id = $attachment instanceof Attachment ? $attachment->id : $attachment;
        $this->attachments()->detach($id);
    }

    /**
     * Detach multiple attachments or all if null.
     *
     * @param  array<int, int|string|Attachment>|null  $attachments
     */
    public function detachAttachments(?array $attachments = null): void
    {
        if ($attachments === null) {
            $this->attachments()->detach();

            return;
        }

        $ids = array_map(
            fn (int|string|Attachment $item): int|string => $item instanceof Attachment ? $item->id : $item,
            $attachments
        );

        $this->attachments()->detach($ids);
    }

    /**
     * Sync attachments with a specific list of IDs.
     *
     * @param  array<int, int|string|Attachment>  $attachments
     * @return array<string, array<int, int|string>>
     */
    public function syncAttachments(array $attachments): array
    {
        $ids = array_map(
            fn (int|string|Attachment $item): int|string => $item instanceof Attachment ? $item->id : $item,
            $attachments
        );

        return $this->attachments()->sync($ids);
    }

    /**
     * Upload and immediately attach a file to this model.
     */
    public function attachFile(
        UploadedFile $file,
        ?string $description = null,
        bool $isPrivate = false,
        ?string $disk = null,
        ?string $userId = null
    ): Attachment {
        $action = resolve(CreateAttachment::class);
        $authId = auth()->id();

        $attachment = $action->handle(
            file: $file,
            description: $description,
            isPrivate: $isPrivate,
            disk: $disk,
            userId: $userId ?? ($authId !== null ? (string) $authId : null),
        );

        $this->attachAttachment($attachment);

        return $attachment;
    }

    /**
     * Upload and immediately attach multiple files to this model.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, Attachment>
     */
    public function attachFiles(
        array $files,
        ?string $description = null,
        bool $isPrivate = false,
        ?string $disk = null,
        ?string $userId = null
    ): Collection {
        $created = collect();

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $created->push($this->attachFile(
                    file: $file,
                    description: $description,
                    isPrivate: $isPrivate,
                    disk: $disk,
                    userId: $userId
                ));
            }
        }

        return $created;
    }
}
