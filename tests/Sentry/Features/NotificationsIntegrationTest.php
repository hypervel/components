<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Sentry\Features\NotificationsFeature;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Notification;
use Hypervel\Tests\Sentry\SentryTestCase;
use Mockery as m;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * @internal
 * @coversNothing
 */
class NotificationsIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.tracing.views' => false,
        'sentry.features' => [
            NotificationsFeature::class,
        ],
    ];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);
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

    public function testSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.notifications' => false,
            'sentry.features' => [
                NotificationsFeature::class,
            ],
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
            'sentry.features' => [
                NotificationsFeature::class,
            ],
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
