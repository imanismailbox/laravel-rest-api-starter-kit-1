<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

final readonly class ImageService
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Determine whether the image can be processed/resampled.
     */
    public function isResizable(UploadedFile|string $file): bool
    {
        $mime = $file instanceof UploadedFile ? $file->getMimeType() : (mime_content_type($file) ?: '');

        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    /**
     * Store and auto-resize image file.
     */
    public function store(
        UploadedFile $file,
        string $directory = 'images',
        ?int $maxWidth = 1920,
        ?int $maxHeight = 1920,
        int $quality = 80,
        ?string $disk = null,
        ?string $customName = null
    ): string {
        $targetDisk = $disk ?: (string) config('filesystems.default');
        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $fileName = $customName ?: Str::uuid()->toString().'.'.$extension;
        $filePath = mb_rtrim($directory, '/').'/'.$fileName;

        if (! $this->isResizable($file)) {
            throw_if(Storage::disk($targetDisk)->putFileAs($directory, $file, $fileName) === false, RuntimeException::class, 'Image could not be stored.');

            return $filePath;
        }

        $image = $this->manager->decode($file->getRealPath() ?: $file->getPathname());

        if ($maxWidth !== null || $maxHeight !== null) {
            $image->scaleDown(width: $maxWidth, height: $maxHeight);
        }

        $encoded = $image->encodeUsingFileExtension($extension, quality: $quality);
        throw_unless(Storage::disk($targetDisk)->put($filePath, (string) $encoded), RuntimeException::class, 'Image could not be stored.');

        return $filePath;
    }

    /**
     * Store a cropped square thumbnail.
     */
    public function storeThumbnail(
        UploadedFile $file,
        string $directory = 'thumbnails',
        int $size = 300,
        int $quality = 80,
        ?string $disk = null
    ): string {
        $targetDisk = $disk ?: (string) config('filesystems.default');
        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $fileName = 'thumb_'.Str::uuid()->toString().'.'.$extension;
        $filePath = mb_rtrim($directory, '/').'/'.$fileName;

        $image = $this->manager->decode($file->getRealPath() ?: $file->getPathname());
        $image->cover(width: $size, height: $size);

        $encoded = $image->encodeUsingFileExtension($extension, quality: $quality);

        throw_unless(Storage::disk($targetDisk)->put($filePath, (string) $encoded), RuntimeException::class, 'Thumbnail could not be stored.');

        return $filePath;
    }

    /**
     * Delete an image from storage.
     */
    public function delete(?string $path, ?string $disk = null): bool
    {
        if (! $path) {
            return false;
        }

        $targetDisk = $disk ?: (string) config('filesystems.default');

        if (Storage::disk($targetDisk)->exists($path)) {
            return Storage::disk($targetDisk)->delete($path);
        }

        return false;
    }
}
