<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Description('Move legacy private attachments off the public disk')]
#[Signature('attachments:secure-private')]
final class SecurePrivateAttachmentsCommand extends Command
{
    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');

        Attachment::query()->where('is_private', true)->whereIn('disk', ['public', 'local'])
            ->eachById(function (Attachment $attachment) use ($public, $private): void {
                if ($attachment->disk === 'public') {
                    $stream = $public->readStream($attachment->path);
                    if (! is_resource($stream)) {
                        throw new RuntimeException("Cannot read attachment {$attachment->id}; no record changed.");
                    }

                    try {
                        if (! $private->put($attachment->path, $stream)
                            || $private->size($attachment->path) !== $public->size($attachment->path)) {
                            throw new RuntimeException("Cannot copy attachment {$attachment->id}; public original retained.");
                        }
                    } finally {
                        fclose($stream);
                    }

                    $attachment->update(['disk' => 'local']);
                }

                // Retry public cleanup too when an earlier run copied the file but could not remove it.
                if ($public->exists($attachment->path)) {
                    if (! $private->exists($attachment->path)
                        || $private->size($attachment->path) !== $public->size($attachment->path)) {
                        throw new RuntimeException("Private copy of attachment {$attachment->id} is missing or incomplete; public original retained.");
                    }

                    if (! $public->delete($attachment->path)) {
                        throw new RuntimeException("Cannot remove public copy of attachment {$attachment->id}; rerun this command.");
                    }
                }
            });

        $this->info('Private attachments secured.');

        return self::SUCCESS;
    }
}
