<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSkipped;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Sentry\Features\NotificationsFeature;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Notification;
use Hypervel\Tests\Sentry\SentryTestCase;
use Mockery as m;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class NotificationsIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.features' => [
            NotificationsFeature::class,
        ],
    ];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('sentry.tracing.views', false);
        $app->instance(ViewFactory::class, m::mock(ViewFactory::class)->shouldIgnoreMissing());
    }

    public function testSpanIsRecorded(): void
    {
        $span = $this->sendNotificationAndRetrieveSpan();

        $this->assertEquals('mail', $span->getDescription());
        $this->assertEquals('mail', $span->getData()['channel']);
        $this->assertEquals('notification.send', $span->getOp());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
    }

    public function testFailedNotificationFinishesItsSpanWithAnError(): void
    {
        $notification = new NotificationsIntegrationTestNotification;
        $notification->id = 'notification-id';
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new NotificationSending('notifiable', $notification, 'mail'));
        $this->dispatchHypervelEvent(new NotificationFailed('notifiable', $notification, 'mail'));

        $span = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
    }

    public function testSkippedNotificationFinishesItsSpanSuccessfully(): void
    {
        $notification = new NotificationsIntegrationTestNotification;
        $notification->id = 'notification-id';
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new NotificationSending('notifiable', $notification, 'mail'));
        $this->dispatchHypervelEvent(new NotificationSkipped('notifiable', $notification, 'mail'));

        $span = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame(SpanStatus::ok(), $span->getStatus());
    }

    public function testSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'traces_sample_rate' => 1.0,
                'tracing.notifications' => false,
                'features' => [NotificationsFeature::class],
            ]),
        ]);

        $this->sendNotificationAndExpectNoSpan();
    }

    public function testBreadcrumbIsRecorded(): void
    {
        $this->sendTestNotification();

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals('notification.sent', $breadcrumb->getCategory());
    }

    public function testBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.notifications' => false,
                'features' => [NotificationsFeature::class],
            ]),
        ]);

        $this->sendTestNotification();

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    private function sendTestNotification(): void
    {
        // We fake the mail so that no actual email is sent but the notification is still sent with all its events
        Mail::fake();

        Notification::route('mail', 'sentry@example.com')->notifyNow(new NotificationsIntegrationTestNotification);
    }

    private function sendNotificationAndRetrieveSpan(): Span
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        return $spans[1];
    }

    private function sendNotificationAndExpectNoSpan(): void
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(1, $spans);
    }
}

class NotificationsIntegrationTestNotification extends \Hypervel\Notifications\Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return new MailMessage;
    }
}
