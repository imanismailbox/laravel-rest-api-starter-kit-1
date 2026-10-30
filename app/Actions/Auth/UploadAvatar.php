<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UploadAvatar
{
    public function __construct(private ImageService $imageService) {}

    /**
     * Upload, resize, and update user avatar image.
     */
    public function handle(User $user, UploadedFile $file): User
    {
        $disk = (string) config('filesystems.default');
        $path = $this->imageService->storeThumbnail(
            file: $file,
            directory: 'avatars',
            size: 500,
            quality: 85,
            disk: $disk,
        );

        try {
            return DB::transaction(function () use ($user, $path, $disk): User {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                $oldPath = $user->avatar;
                $user->update(['avatar' => $path]);

                if ($oldPath && ! str_starts_with($oldPath, 'http')) {
                    DB::afterCommit(fn () => rescue(fn () => $this->imageService->delete($oldPath, $disk), report: true));
                }

                return $user;
            });
        } catch (Throwable $exception) {
            rescue(fn () => $this->imageService->delete($path, $disk), report: true);

            throw $exception;
        }
    }
}
