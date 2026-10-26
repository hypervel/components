<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Events\Dispatcher;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OutboxWrapperTest extends TestCase
{
    #[DataProvider('producerTypes')]
    public function testConstructedProducersPropagateEffectiveSelection(string $plain, string $marked, string $wrapper): void
    {
        $source = new $plain;
        $job = $this->wrap($source, $wrapper);
        $this->assertArrayNotHasKey('viaOutbox', get_object_vars($job));
        $this->assertArrayNotHasKey('outboxDatabase', get_object_vars($job));

        $source->onOutboxDatabase('billing');
        $job = $this->wrap($source, $wrapper);
        $this->assertTrue($job->viaOutbox);
        $this->assertSame('billing', $job->outboxDatabase);

        $source = new $marked;
        $this->assertTrue($this->wrap($source, $wrapper)->viaOutbox);

        $source->viaOutbox = null;
        $this->assertTrue($this->wrap($source, $wrapper)->viaOutbox);

        $source->withoutOutbox();
        $job = $this->wrap($source, $wrapper);
        $this->assertFalse($job->viaOutbox);
        $this->assertArrayNotHasKey('outboxDatabase', get_object_vars($job));
    }

    /**
     * Provide the independent producer wrapper boundaries.
     */
    public static function producerTypes(): array
    {
        return [
            'mail' => [OutboxMailable::class, MarkedOutboxMailable::class, SendQueuedMailable::class],
            'notification' => [OutboxNotification::class, MarkedOutboxNotification::class, SendQueuedNotifications::class],
            'broadcast' => [OutboxBroadcast::class, MarkedOutboxBroadcast::class, BroadcastEvent::class],
        ];
    }

    #[DataProvider('listenerTypes')]
    public function testListenerDefaultsPropagateToQueuedJobs(string $listener, ?bool $selected, ?string $database): void
    {
        $queue = new QueueFake(new Container);
        $dispatcher = new Dispatcher;
        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen('order.created', $listener);

        $dispatcher->dispatch('order.created');

        $queue->assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($selected, $database): bool {
            $this->assertSame($selected, $job->viaOutbox ?? null);
            $this->assertSame($database, $job->outboxDatabase ?? null);

            if ($selected === null) {
                $this->assertArrayNotHasKey('viaOutbox', get_object_vars($job));
            }

            return true;
        });
    }

    /**
     * Provide listener defaults read without invoking constructors.
     */
    public static function listenerTypes(): array
    {
        return [
            'absent' => [OutboxListener::class, null, null],
            'marker with uninitialized state' => [MarkedOutboxListener::class, true, null],
            'null with marker' => [NullMarkedOutboxListener::class, true, 'billing'],
            'explicit false overrides marker' => [DirectMarkedOutboxListener::class, false, null],
            'explicit true' => [SelectedOutboxListener::class, true, 'billing'],
        ];
    }

    /**
     * Construct the wrapper through its normal producer boundary.
     */
    protected function wrap(object $source, string $wrapper): object
    {
        return $wrapper === SendQueuedNotifications::class
            ? new SendQueuedNotifications([], $source)
            : new $wrapper($source);
    }
}

class OutboxMailable extends Mailable
{
    use Queueable;
}

class MarkedOutboxMailable extends OutboxMailable implements ShouldQueueViaOutbox
{
}

class OutboxNotification extends Notification
{
    use Queueable;
}

class MarkedOutboxNotification extends OutboxNotification implements ShouldQueueViaOutbox
{
}

class OutboxBroadcast
{
    use Queueable;
}

class MarkedOutboxBroadcast extends OutboxBroadcast implements ShouldQueueViaOutbox
{
}

class OutboxListener implements ShouldQueue
{
    /**
     * Handle the queued event.
     */
    public function handle(): void
    {
    }
}

class MarkedOutboxListener extends OutboxListener implements ShouldQueueViaOutbox
{
    use Queueable;
}

class NullMarkedOutboxListener extends OutboxListener implements ShouldQueueViaOutbox
{
    public ?bool $viaOutbox = null;

    public string $outboxDatabase = 'billing';
}

class DirectMarkedOutboxListener extends OutboxListener implements ShouldQueueViaOutbox
{
    public bool $viaOutbox = false;
}

class SelectedOutboxListener extends OutboxListener
{
    public bool $viaOutbox = true;

    public string $outboxDatabase = 'billing';
}
