<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Container\Container;
use Hypervel\Horizon\Contracts\LongWaitDetectedNotification as LongWaitDetectedNotificationContract;
use Hypervel\Horizon\Events\LongWaitDetected;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\Notifications\LongWaitDetected as LongWaitDetectedNotification;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Support\Facades\Notification;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class NotificationOverridesTest extends IntegrationTestCase
{
    public function testCustomNotificationsAreSentIfSpecified()
    {
        Notification::fake();

        Horizon::routeMailNotificationsTo('taylor@laravel.com');

        Container::getInstance()->bind(LongWaitDetectedNotificationContract::class, CustomLongWaitDetectedNotification::class);

        event(new LongWaitDetected('redis', 'test-queue-2', 60));

        Notification::assertSentOnDemand(CustomLongWaitDetectedNotification::class);
    }

    public function testNormalNotificationsAreSentIfNotSpecified()
    {
        Notification::fake();

        Horizon::routeMailNotificationsTo('taylor@laravel.com');

        event(new LongWaitDetected('redis', 'test-queue-2', 60));

        Notification::assertSentOnDemand(LongWaitDetectedNotification::class);
    }
}

class CustomLongWaitDetectedNotification extends LongWaitDetectedNotification implements LongWaitDetectedNotificationContract
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->line('This is a custom notification for a long wait.');
    }
}
