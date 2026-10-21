<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Fixtures;

use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;

class SentMessageMailNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('Example notification with attachment.')
            ->attach(dirname(__DIR__, 2) . '/Integration/Mail/Fixtures/blank_document.pdf', [
                'as' => 'blank_document.pdf',
                'mime' => 'application/pdf',
            ]);
    }
}
