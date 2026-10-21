<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Fixtures;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\SerializesModels;
use Hypervel\Tests\Integration\Queue\DeleteNotificationWhenMissingModelTest\DeleteNotificationTestModel;

#[DeleteWhenMissingModels]
class DeleteWhenMissingNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public static bool $sent = false;

    /**
     * Create the notification for the model.
     */
    public function __construct(public DeleteNotificationTestModel $model)
    {
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        static::$sent = true;

        return new MailMessage;
    }
}
