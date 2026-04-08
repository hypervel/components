<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notification;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\NotificationWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    NotificationWatcher::class => true,
])]
class NotificationWatcherTest extends FeatureTestCase
{
    public function testNotificationWatcherRegistersEntry()
    {
        $notifiable = new AnonymousNotifiable;
        $notifiable->routes = ['route1', 'route2'];
        $event = new NotificationSent(
            $notifiable,
            new Notification,
            'channel',
            'response'
        );

        $this->app->make(Dispatcher::class)
            ->dispatch($event);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::NOTIFICATION, $entry->type);
        $this->assertSame(Notification::class, $entry->content['notification']);
        $this->assertFalse($entry->content['queued']);
        $this->assertSame($entry->content['notifiable'], 'Anonymous:route1,route2');
        $this->assertSame('channel', $entry->content['channel']);
        $this->assertSame('response', $entry->content['response']);
    }
}
