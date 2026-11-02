<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Throwable;

/**
 * @property string|null $user_id Declared here because the migration picks its type from config('attachment.user_key_type').
 */
#[Fillable([
    'user_id',
    'name',
    'description',
    'path',
    'disk',
    'mime',
    'size',
    'is_private',
])]
final class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'mime', 'size', 'is_private'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The user who uploaded this attachment.
     */
    /**
     * @return BelongsTo<Model, $this>
     */
    public function uploader(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('attachment.user_model', User::class);

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Polymorphic relation to an attachable model class.
     *
     * @param  class-string<Model>  $modelClass
     * @return MorphToMany<Model, $this>
     */
    public function attachables(string $modelClass): MorphToMany
    {
        return $this->morphedByMany($modelClass, 'attachable', 'attachables');
    }

    /**
     * Get accessible URL for the attachment file.
     */
    public function url(): string
    {
        $disk = $this->disk ?: (string) config('filesystems.default');

        if (! $this->is_private) {
            return Storage::disk($disk)->url($this->path);
        }

        try {
            return Storage::disk($disk)->temporaryUrl($this->path, now()->addMinutes(60));
        } catch (Throwable) {
            return $this->downloadUrl();
        }
    }

    /**
     * Get download URL via API stream endpoint.
     */
    public function downloadUrl(): string
    {
        return route('api.v1.attachments.download', ['attachment' => $this->id]);
    }

    /**
     * Human readable file size (e.g. 1.25 MB).
     */
    public function humanSize(): string
    {
        $bytes = (float) $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * File extension.
     */
    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION) ?: pathinfo($this->name, PATHINFO_EXTENSION) ?: '';
    }

    /**
     * Categorize media type (image, video, audio, document, archive, data, file).
     */
    public function type(): string
    {
        $ext = mb_strtolower($this->extension());
        $mime = (string) $this->mime;

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'tif', 'tiff'], true)) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) {
            return 'video';
        }

        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) {
            return 'audio';
        }

        if (in_array($ext, ['pdf', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'txt', 'rtf', 'csv'], true)) {
            return 'document';
        }

        if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)) {
            return 'archive';
        }

        if (in_array($ext, ['json', 'geojson', 'kml', 'kmz', 'gpx', 'topojson', 'gpkg', 'shp', 'shx', 'dbf', 'prj', 'mbtiles'], true)) {
            return 'data';
        }

        return 'file';
    }

    /**
     * Determine if attachment is an image.
     */
    public function isImage(): bool
    {
        return $this->type() === 'image';
    }

    /**
     * Determine if attachment is a video.
     */
    public function isVideo(): bool
    {
        return $this->type() === 'video';
    }

    /**
     * Determine if attachment is an audio.
     */
    public function isAudio(): bool
    {
        return $this->type() === 'audio';
    }

    /**
     * Determine if attachment is a document.
     */
    public function isDocument(): bool
    {
        return $this->type() === 'document';
    }

    /**
     * Delete physical file from storage disk.
     */
    public function deleteStorageFile(): bool
    {
        $disk = $this->disk ?: (string) config('filesystems.default');

        if (Storage::disk($disk)->exists($this->path)) {
            return Storage::disk($disk)->delete($this->path);
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_private' => 'boolean',
        ];
    }
}
