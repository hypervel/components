<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Sentry\Features\NotificationsFeature;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Notification;
use Hypervel\Tests\Sentry\SentryTestCase;
use Hypervel\View\Contracts\Factory as ViewFactory;
use Mockery as m;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * @internal
 * @coversNothing
 */
class NotificationsFeatureTest extends SentryTestCase
{
    use RunTestsInCoroutine;

    protected array $defaultSetupConfig = [
        'sentry.breadcrumbs.notifications' => true,
        'sentry.tracing.notifications' => true,
        'sentry.features' => [
            NotificationsFeature::class,
        ],
    ];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);
        $this->app->instance(ViewFactory::class, m::mock(ViewFactory::class)->shouldIgnoreMissing());
    }

    public function testSpanIsRecorded(): void
    {
        $span = $this->sendNotificationAndRetrieveSpan();

        $this->assertEquals('mail', $span->getDescription());
        $this->assertEquals('mail', $span->getData()['channel']);
        $this->assertEquals('notification.send', $span->getOp());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
    }

    public function testSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.notifications' => false,
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
            'sentry.breadcrumbs.notifications' => false,
        ]);

        $this->sendTestNotification();

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    private function sendNotificationAndRetrieveSpan(): Span
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Find the notification.send span
        $notificationSpans = array_filter($spans, fn ($span) => $span->getOp() === 'notification.send');

        $this->assertCount(1, $notificationSpans, 'Expected exactly one notification.send span');

        return array_values($notificationSpans)[0];
    }

    private function sendTestNotification(): void
    {
        // We fake the mail so that no actual email is sent but the notification is still sent with all it's events
        Mail::fake();

        Notification::route('mail', 'sentry@example.com')->notifyNow(new NotificationsIntegrationTestNotification());
    }

    private function sendNotificationAndExpectNoSpan(): void
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Should not have any notification.send spans when tracing is disabled
        $notificationSpans = array_filter($spans, fn ($span) => $span->getOp() === 'notification.send');
        $this->assertCount(0, $notificationSpans, 'Expected no notification.send spans when tracing is disabled');
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
        return new MailMessage();
    }
}
