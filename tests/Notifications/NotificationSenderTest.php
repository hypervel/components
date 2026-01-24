<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Bus\Contracts\Dispatcher as BusDispatcherContract;
use Hypervel\Bus\Queueable;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationSender;
use Hypervel\Contracts\Queue\ShouldQueue;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface as EventDispatcher;

/**
 * @internal
 * @coversNothing
 */
class NotificationSenderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testItCanSendNotificationsWithAStringVia()
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')
            ->once()
            ->andReturnSelf();
        $manager->shouldReceive('send')
            ->once();
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithStringVia());
    }

    public function testItCanSendNotificationsWithAnEmptyStringVia()
    {
        $notifiable = new AnonymousNotifiable();
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = m::mock(EventDispatcher::class);
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithEmptyStringVia());
    }

    public function testItCannotSendNotificationsViaDatabaseForAnonymousNotifiables()
    {
        $notifiable = new AnonymousNotifiable();
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = m::mock(EventDispatcher::class);
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithDatabaseVia());
    }

    public function testItCanSendQueuedNotificationsThroughMiddleware()
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->withArgs(function ($job) {
                return $job->middleware[0] instanceof TestNotificationMiddleware;
            });
        $events = m::mock(EventDispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithMiddleware());
    }

    public function testItCanSendQueuedMultiChannelNotificationsThroughDifferentMiddleware()
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->middleware[0] instanceof TestMailNotificationMiddleware;
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->middleware[0] instanceof TestDatabaseNotificationMiddleware;
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return empty($job->middleware);
            });
        $events = m::mock(EventDispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyMultiChannelNotificationWithConditionalMiddleware());
    }

    public function testItCanSendQueuedWithViaConnectionsNotifications()
    {
        $notifiable = new AnonymousNotifiable();
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->connection === 'sync' && $job->channels === ['database'];
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->connection === null && $job->channels === ['mail'];
            });

        $events = m::mock(EventDispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithViaConnections());
    }
}

class DummyNotificationWithStringVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     *
     * @param mixed $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return 'mail';
    }
}

class DummyNotificationWithEmptyStringVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     *
     * @param mixed $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return '';
    }
}

class DummyNotificationWithDatabaseVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     *
     * @param mixed $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return 'database';
    }
}

class DummyNotificationWithViaConnections extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function viaConnections()
    {
        return [
            'database' => 'sync',
        ];
    }
}

class DummyNotificationWithMiddleware extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return 'mail';
    }

    public function middleware()
    {
        return [
            new TestNotificationMiddleware(),
        ];
    }
}

class DummyMultiChannelNotificationWithConditionalMiddleware extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return [
            'mail',
            'database',
            'broadcast',
        ];
    }

    public function middleware($notifiable, $channel)
    {
        return match ($channel) {
            'mail' => [new TestMailNotificationMiddleware()],
            'database' => [new TestDatabaseNotificationMiddleware()],
            default => []
        };
    }
}

class TestNotificationMiddleware
{
    public function handle($command, $next)
    {
        return $next($command);
    }
}

class TestMailNotificationMiddleware
{
    public function handle($command, $next)
    {
        return $next($command);
    }
}

class TestDatabaseNotificationMiddleware
{
    public function handle($command, $next)
    {
        return $next($command);
    }
}
