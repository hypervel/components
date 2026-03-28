<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Notification as NotificationFacade;
use Hypervel\Support\Testing\Fakes\NotificationFake;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SendingNotificationsViaAnonymousNotifiableTest extends TestCase
{
    public function testMailIsSent()
    {
        $notifiable = (new AnonymousNotifiable())
            ->route('testchannel', 'enzo')
            ->route('anothertestchannel', 'enzo@deepblue.com');

        NotificationFacade::send(
            $notifiable,
            new TestMailNotificationForAnonymousNotifiable()
        );

        $this->assertEquals([
            'enzo', 'enzo@deepblue.com',
        ], $_SERVER['__notifiable.route']);
    }

    public function testAnonymousNotifiableWithMultipleRoutes()
    {
        $_SERVER['__notifiable.route'] = [];

        NotificationFacade::routes([
            'testchannel' => 'enzo',
            'anothertestchannel' => 'enzo@deepblue.com',
        ])->notify(new TestMailNotificationForAnonymousNotifiable());

        $this->assertEquals([
            'enzo', 'enzo@deepblue.com',
        ], $_SERVER['__notifiable.route']);
    }

    public function testFaking()
    {
        $fake = NotificationFacade::fake();

        $this->assertInstanceOf(NotificationFake::class, $fake);

        $notifiable = (new AnonymousNotifiable())
            ->route('testchannel', 'enzo')
            ->route('anothertestchannel', 'enzo@deepblue.com');

        NotificationFacade::locale('it')->send(
            $notifiable,
            new TestMailNotificationForAnonymousNotifiable()
        );

        NotificationFacade::assertSentTo(
            new AnonymousNotifiable(),
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
    public function via($notifiable)
    {
        return [TestCustomChannel::class, AnotherTestCustomChannel::class];
    }
}

class TestCustomChannel
{
    public function send($notifiable, $notification)
    {
        $_SERVER['__notifiable.route'][] = $notifiable->routeNotificationFor('testchannel');
    }
}

class AnotherTestCustomChannel
{
    public function send($notifiable, $notification)
    {
        $_SERVER['__notifiable.route'][] = $notifiable->routeNotificationFor('anothertestchannel');
    }
}
