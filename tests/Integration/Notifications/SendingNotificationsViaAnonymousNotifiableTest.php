<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Notification as NotificationFacade;
use Hypervel\Support\Testing\Fakes\NotificationFake;
use Hypervel\Testbench\TestCase;

class SendingNotificationsViaAnonymousNotifiableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['__notifiable.route'] = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['__notifiable.route']);

        parent::tearDown();
    }

    public function testMailIsSent(): void
    {
        $notifiable = (new AnonymousNotifiable)
            ->route('testchannel', 'enzo')
            ->route('anothertestchannel', 'enzo@deepblue.com');

        NotificationFacade::send(
            $notifiable,
            new TestMailNotificationForAnonymousNotifiable
        );

        $this->assertEquals([
            'enzo', 'enzo@deepblue.com',
        ], $_SERVER['__notifiable.route']);
    }

    public function testAnonymousNotifiableWithMultipleRoutes(): void
    {
        NotificationFacade::routes([
            'testchannel' => 'enzo',
            'anothertestchannel' => 'enzo@deepblue.com',
        ])->notify(new TestMailNotificationForAnonymousNotifiable);

        $this->assertEquals([
            'enzo', 'enzo@deepblue.com',
        ], $_SERVER['__notifiable.route']);
    }

    public function testFaking(): void
    {
        $fake = NotificationFacade::fake();

        $this->assertInstanceOf(NotificationFake::class, $fake);

        $notifiable = (new AnonymousNotifiable)
            ->route('testchannel', 'enzo')
            ->route('anothertestchannel', 'enzo@deepblue.com');

        NotificationFacade::locale('it')->send(
            $notifiable,
            new TestMailNotificationForAnonymousNotifiable
        );

        NotificationFacade::assertSentTo(
            new AnonymousNotifiable,
            TestMailNotificationForAnonymousNotifiable::class,
            function ($notification, $channels, $notifiable, $locale) {
                return $notifiable->routes['testchannel'] === 'enzo'
                    && $notifiable->routes['anothertestchannel'] === 'enzo@deepblue.com'
                    && $locale === 'it';
            }
        );
    }
}

class TestMailNotificationForAnonymousNotifiable extends Notification
{
    public function via(mixed $notifiable): array
    {
        return [TestCustomChannel::class, AnotherTestCustomChannel::class];
    }
}

class TestCustomChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $_SERVER['__notifiable.route'][] = $notifiable->routeNotificationFor('testchannel');
    }
}

class AnotherTestCustomChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $_SERVER['__notifiable.route'][] = $notifiable->routeNotificationFor('anothertestchannel');
    }
}
