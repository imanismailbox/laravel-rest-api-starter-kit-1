<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Models\Attachment;
use Illuminate\Support\Facades\DB;

final readonly class DeleteAttachment
{
    public function handle(Attachment $attachment): bool
    {
        return DB::transaction(function () use ($attachment): bool {
            $deleted = (bool) $attachment->delete();

            if ($deleted) {
                DB::afterCommit(fn (): bool => $attachment->deleteStorageFile());
            }

            return $deleted;
        });
    }
}
