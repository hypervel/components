<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationSender;
use Hypervel\Testbench\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class NotificationFailedEventTest extends TestCase
{
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
    public function __construct(
        private readonly Dispatcher $events,
        private readonly string $channel,
        private readonly ?Closure $afterDispatch = null,
    ) {
    }

    public function send(mixed $notifiable, Notification $notification): never
    {
        $this->events->dispatch(new NotificationFailed($notifiable, $notification, $this->channel));

        if ($this->afterDispatch !== null) {
            ($this->afterDispatch)();
        }

        throw new RuntimeException("{$this->channel} failed.");
    }
}

class NotificationFailedEventSuccessfulChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
    }
}

class NotificationFailedEventThrowingChannel
{
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
