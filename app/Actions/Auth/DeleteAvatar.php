<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteAvatar
{
    /**
     * Delete user avatar and remove from storage.
     */
    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $disk = (string) config('filesystems.default');
            $oldPath = $user->avatar;
            $user->update(['avatar' => null]);

            if ($oldPath && ! str_starts_with($oldPath, 'http')) {
                DB::afterCommit(fn () => rescue(fn () => Storage::disk($disk)->delete($oldPath), report: true));
            }

            return $user;
        });
    }
}
