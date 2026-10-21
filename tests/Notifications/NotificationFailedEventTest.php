<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationSender;
use Hypervel\Testbench\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class NotificationFailedEventTest extends TestCase
{
    public function testNotificationFailedDispatchedOnlyOnceWhenFailed(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $manager = $this->app->make(ChannelManager::class);
        $dispatched = $this->recordFailures($events);
        $sent = 0;

        $events->listen(NotificationSent::class, function () use (&$sent): void {
            ++$sent;
        });
        $manager->extend('test', fn () => new NotificationFailedEventDispatchingChannel($events, 'test'));

        // Use the real provider listener to suppress the sender's duplicate failure event.
        $exception = $this->captureRuntimeException(fn () => $manager->sendNow(
            new NotificationFailedEventNotifiable,
            new NotificationFailedEventNotification,
            ['test'],
        ));

        $this->assertSame('test failed.', $exception->getMessage());
        $this->assertSame(['test'], $dispatched());
        $this->assertSame(0, $sent);
    }

    public function testChannelOwnedFailureSurvivesNestedSuccess(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $manager = $this->app->make(ChannelManager::class);
        $notifiable = new NotificationFailedEventNotifiable;
        $notification = new NotificationFailedEventNotification;
        $dispatched = $this->recordFailures($events);

        $manager->extend('nested-success', fn () => new NotificationFailedEventSuccessfulChannel);
        $manager->extend('outer', fn () => new NotificationFailedEventDispatchingChannel(
            $events,
            'outer',
            fn () => $manager->sendNow($notifiable, $notification, ['nested-success']),
        ));

        $this->captureRuntimeException(
            fn () => $manager->sendNow($notifiable, $notification, ['outer'])
        );

        $this->assertSame(['outer'], $dispatched());
        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));
    }

    public function testNestedChannelOwnedFailuresRestoreTheOuterAttempt(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $manager = $this->app->make(ChannelManager::class);
        $notifiable = new NotificationFailedEventNotifiable;
        $notification = new NotificationFailedEventNotification;
        $dispatched = $this->recordFailures($events);

        $manager->extend('nested', fn () => new NotificationFailedEventDispatchingChannel($events, 'nested'));
        $manager->extend('outer', fn () => new NotificationFailedEventDispatchingChannel(
            $events,
            'outer',
            fn () => $manager->sendNow($notifiable, $notification, ['nested']),
        ));

        $exception = $this->captureRuntimeException(
            fn () => $manager->sendNow($notifiable, $notification, ['outer'])
        );

        $this->assertSame('nested failed.', $exception->getMessage());
        $this->assertSame(['outer', 'nested'], $dispatched());
        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));
    }

    public function testSequentialAttemptsDoNotShareFailureState(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $manager = $this->app->make(ChannelManager::class);
        $notifiable = new NotificationFailedEventNotifiable;
        $notification = new NotificationFailedEventNotification;
        $dispatched = $this->recordFailures($events);

        $manager->extend('channel-owned', fn () => new NotificationFailedEventDispatchingChannel($events, 'channel-owned'));
        $manager->extend('sender-owned', fn () => new NotificationFailedEventThrowingChannel);

        $this->captureRuntimeException(
            fn () => $manager->sendNow($notifiable, $notification, ['channel-owned'])
        );
        $this->captureRuntimeException(
            fn () => $manager->sendNow($notifiable, $notification, ['sender-owned'])
        );

        $this->assertSame(['channel-owned', 'sender-owned'], $dispatched());
    }

    public function testReportedFailureWithoutAnExceptionDoesNotAffectTheNextAttempt(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $manager = $this->app->make(ChannelManager::class);
        $notifiable = new NotificationFailedEventNotifiable;
        $notification = new NotificationFailedEventNotification;
        $dispatched = $this->recordFailures($events);

        $manager->extend('reported', fn () => new NotificationFailedEventReportingChannel($events));
        $manager->extend('throwing', fn () => new NotificationFailedEventThrowingChannel);

        $manager->sendNow($notifiable, $notification, ['reported']);

        // Check cleanup before another attempt can overwrite a leaked failure marker.
        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));

        $this->captureRuntimeException(
            fn () => $manager->sendNow($notifiable, $notification, ['throwing'])
        );

        $this->assertSame(['reported', 'throwing'], $dispatched());
    }

    public function testSuccessfulAndExceptionalAttemptsRemoveTheirContextState(): void
    {
        $manager = $this->app->make(ChannelManager::class);
        $notifiable = new NotificationFailedEventNotifiable;
        $notification = new NotificationFailedEventNotification;

        $manager->extend('success', fn () => new NotificationFailedEventSuccessfulChannel);
        $manager->extend('failure', fn () => new NotificationFailedEventThrowingChannel);

        $manager->sendNow($notifiable, $notification, ['success']);
        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));

        $this->captureRuntimeException(
            fn () => $manager->sendNow($notifiable, $notification, ['failure'])
        );
        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));
    }

    public function testExternalFailureEventDoesNotCreateAttemptState(): void
    {
        $this->app->make(Dispatcher::class)->dispatch(new NotificationFailed(
            new NotificationFailedEventNotifiable,
            new NotificationFailedEventNotification,
            'external',
        ));

        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));
    }

    public function testFailureStateIsIsolatedBetweenSiblingCoroutines(): void
    {
        $events = $this->app->make(Dispatcher::class);

        [$activeAttempt, $sibling] = parallel([
            function () use ($events) {
                CoroutineContext::set(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, false);
                $events->dispatch(new NotificationFailed(
                    new NotificationFailedEventNotifiable,
                    new NotificationFailedEventNotification,
                    'active',
                ));
                usleep(10_000);

                return CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY);
            },
            function () {
                usleep(5_000);

                return CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY);
            },
        ]);

        $this->assertTrue($activeAttempt);
        $this->assertNull($sibling);
    }

    /**
     * Record the channels from dispatched failure events.
     */
    protected function recordFailures(Dispatcher $events): Closure
    {
        $dispatched = [];

        $events->listen(NotificationFailed::class, function (NotificationFailed $event) use (&$dispatched): void {
            $dispatched[] = $event->channel;
        });

        return static function () use (&$dispatched): array {
            return $dispatched;
        };
    }

    /**
     * Capture an expected channel failure.
     */
    protected function captureRuntimeException(Closure $callback): RuntimeException
    {
        try {
            $callback();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        $this->fail('Expected the notification channel to throw a RuntimeException.');
    }
}

class NotificationFailedEventDispatchingChannel
{
    /**
     * Create a channel that reports and throws a failure.
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly string $channel,
        private readonly ?Closure $afterDispatch = null,
    ) {
    }

    /**
     * Report the failure before invoking the nested send and throwing.
     */
    public function send(mixed $notifiable, Notification $notification): never
    {
        $this->events->dispatch(new NotificationFailed($notifiable, $notification, $this->channel));

        if ($this->afterDispatch !== null) {
            ($this->afterDispatch)();
        }

        throw new RuntimeException("{$this->channel} failed.");
    }
}

class NotificationFailedEventReportingChannel
{
    /**
     * Create a channel that reports a failure without throwing.
     */
    public function __construct(private readonly Dispatcher $events)
    {
    }

    /**
     * Report the delivery failure without an exception.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $this->events->dispatch(new NotificationFailed($notifiable, $notification, 'reported'));
    }
}

class NotificationFailedEventSuccessfulChannel
{
    /**
     * Complete the delivery without an exception.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
    }
}

class NotificationFailedEventThrowingChannel
{
    /**
     * Throw a delivery failure for the sender to report.
     */
    public function send(mixed $notifiable, Notification $notification): never
    {
        throw new RuntimeException('Channel failed.');
    }
}

class NotificationFailedEventNotifiable
{
}

class NotificationFailedEventNotification extends Notification
{
}
