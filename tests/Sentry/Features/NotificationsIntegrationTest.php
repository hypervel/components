<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Events\NotificationSkipped;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification as BaseNotification;
use Hypervel\Sentry\Features\NotificationsIntegration;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Facades\Notification;
use Hypervel\Tests\Sentry\TestCase;
use Mockery as m;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Throwable;

class NotificationsIntegrationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.features' => [
            NotificationsIntegration::class,
        ],
    ];

    /**
     * Define the test environment.
     */
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

        $this->assertFalse($this->app->make('events')->hasListeners(NotificationSent::class));

        $span = $this->sendNotificationAndRetrieveSpan();

        $this->assertSame(SpanStatus::ok(), $span->getStatus());
        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    public function testNestedNotificationSpanIsAChildOfTheOuterNotificationSpan(): void
    {
        [$transaction, $spanAfterNestedNotification] = $this->sendNotificationWithNestedNotification(static function (): void {
            Notification::route('mail', 'nested@example.com')->notifyNow(new NotificationsIntegrationTestNotification);
        });

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(3, $spans);
        $this->assertSame($spans[1], $spanAfterNestedNotification);
        $this->assertSame($spans[1]->getSpanId(), $spans[2]->getParentSpanId());
        $this->assertSame(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame(SpanStatus::ok(), $spans[2]->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testSkippedNestedNotificationDoesNotFinishTheOuterNotificationSpan(): void
    {
        [$transaction, $spanAfterNestedNotification] = $this->sendNotificationWithNestedNotification(static function (): void {
            Notification::route('mail', 'nested@example.com')->notifyNow(new NotificationsIntegrationTestSkippedNotification);
        });

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame($spans[1], $spanAfterNestedNotification);
        $this->assertSame(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testNestedNotificationFailingBeforeSendingDoesNotFinishTheOuterNotificationSpan(): void
    {
        $exception = new RuntimeException('The nested notification failed.');
        $caughtException = null;

        [$transaction, $spanAfterNestedNotification] = $this->sendNotificationWithNestedNotification(static function () use ($exception, &$caughtException): void {
            try {
                Notification::route('mail', 'nested@example.com')->notifyNow(new NotificationsIntegrationTestFailingNotification($exception));
            } catch (RuntimeException $caughtException) {
            }
        });

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertSame($exception, $caughtException);
        $this->assertCount(2, $spans);
        $this->assertSame($spans[1], $spanAfterNestedNotification);
        $this->assertSame(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    /**
     * Send the test notification by mail.
     */
    private function sendTestNotification(): void
    {
        // We fake the mail so that no actual email is sent but the notification is still sent with all its events
        Mail::fake();

        Notification::route('mail', 'sentry@example.com')->notifyNow(new NotificationsIntegrationTestNotification);
    }

    /**
     * Send the test notification within a transaction and return its span.
     */
    private function sendNotificationAndRetrieveSpan(): Span
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        return $spans[1];
    }

    /**
     * Send the test notification within a transaction and assert that no span was recorded.
     */
    private function sendNotificationAndExpectNoSpan(): void
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(1, $spans);
    }

    /**
     * Send a notification that sends a nested notification during delivery.
     *
     * @return array{Transaction, null|Span} the transaction and the current span after the nested notification
     */
    private function sendNotificationWithNestedNotification(Closure $sendNestedNotification): array
    {
        $transaction = $this->startTransaction();
        $spanAfterNestedNotification = null;

        Mail::fake();

        Notification::route('mail', 'sentry@example.com')->notifyNow(new NotificationsIntegrationTestNestingNotification(
            static function () use ($sendNestedNotification, &$spanAfterNestedNotification): void {
                $sendNestedNotification();

                $spanAfterNestedNotification = SentrySdk::getCurrentHub()->getSpan();
            }
        ));

        return [$transaction, $spanAfterNestedNotification];
    }
}

class NotificationsIntegrationTestNotification extends BaseNotification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return new MailMessage;
    }
}

class NotificationsIntegrationTestNestingNotification extends NotificationsIntegrationTestNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly Closure $sendNestedNotification,
    ) {
    }

    /**
     * Send the nested notification, then get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        ($this->sendNestedNotification)();

        return parent::toMail($notifiable);
    }
}

class NotificationsIntegrationTestSkippedNotification extends NotificationsIntegrationTestNotification
{
    /**
     * Determine if the notification should be sent.
     */
    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return false;
    }
}

class NotificationsIntegrationTestFailingNotification extends NotificationsIntegrationTestNotification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly Throwable $exception,
    ) {
    }

    /**
     * Fail before the notification is sent.
     */
    public function shouldSend(mixed $notifiable, string $channel): never
    {
        throw $this->exception;
    }
}
