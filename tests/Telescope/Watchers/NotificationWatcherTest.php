<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification as BaseNotification;
use Hypervel\Support\Facades\Notification;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\NotificationWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('mail.driver', 'array')]
#[WithConfig('telescope.watchers', [
    NotificationWatcher::class => true,
])]
class NotificationWatcherTest extends FeatureTestCase
{
    public function testNotificationWatcherRegistersEntry()
    {
        $this->performNotificationAssertions('mail', 'telescope@hypervel.org');
    }

    public function testNotificationWatcherRegistersArrayRoutes()
    {
        $this->performNotificationAssertions('mail', ['telescope@hypervel.org', 'nestedroute@hypervel.org']);
    }

    private function performNotificationAssertions(string $channel, array|string $route): void
    {
        Notification::route($channel, $route)
            ->notify(new BoomerangNotification);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::NOTIFICATION, $entry->type);
        $this->assertSame(BoomerangNotification::class, $entry->content['notification']);
        $this->assertFalse($entry->content['queued']);
        $this->assertStringContainsString(is_array($route) ? implode(',', $route) : $route, $entry->content['notifiable']);
        $this->assertSame($channel, $entry->content['channel']);
        $this->assertEmpty($entry->content['response']);
    }
}

class BoomerangNotification extends BaseNotification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->greeting('Throw a boomerang')
            ->line('They are fun!');
    }
}
