<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Bus\Queueable;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification as BaseNotification;
use Hypervel\Support\Facades\Notification;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\MailWatcher;
use Hypervel\Telescope\Watchers\NotificationWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

#[WithConfig('mail.driver', 'array')]
#[WithConfig('telescope.watchers', [
    MailWatcher::class => true,
    NotificationWatcher::class => true,
])]
class MailNotificationTest extends FeatureTestCase
{
    public function testMailWatcherRegistersValidHtml()
    {
        Notification::route('mail', 'to@hypervel.org')
            ->notify(new TestMailNotification);

        $entry = $this->loadTelescopeEntries()->firstWhere('type', EntryType::MAIL);

        $this->assertSame(EntryType::MAIL, $entry->type);
        $this->assertStringStartsWith('<!DOCTYPE html', $entry->content['html']);
    }
}

class TestMailNotification extends BaseNotification
{
    use Queueable;

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Check out this awesome HTML and raw email!')
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }
}
