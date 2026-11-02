<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

final class GenericFcmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?string $image = null,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function via(mixed $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(mixed $notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->notification(
                notification: new FcmNotification(
                    title: $this->title,
                    body: $this->body,
                    image: $this->image,
                )
            )
            ->data($this->data);
    }
}
