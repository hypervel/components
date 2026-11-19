<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Events\Dispatcher;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Queue\Attributes\RetryFor;
use Hypervel\Queue\NullQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;

class QueueRetryForAttributeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
    }

    public function testJobExpirationIsCalculatedFromTheAttribute(): void
    {
        $this->assertSame(
            CarbonImmutable::now()->addSeconds(90)->getTimestamp(),
            (new NullQueue)->getJobExpiration(new RetryForAttributeJob)
        );
    }

    public function testAttributeAcceptsMinutesAndHours(): void
    {
        $this->assertSame(90, (new RetryFor(90))->retryFor);
        $this->assertSame(90, (new RetryFor(seconds: 90))->retryFor);
        $this->assertSame(600, (new RetryFor(minutes: 10))->retryFor);
        $this->assertSame(36000, (new RetryFor(hours: 10))->retryFor);
        $this->assertSame(5430, (new RetryFor(seconds: 30, minutes: 30, hours: 1))->retryFor);
    }

    public function testJobExpirationIsCalculatedFromNamedUnits(): void
    {
        $this->assertSame(
            CarbonImmutable::now()->addMinutes(10)->getTimestamp(),
            (new NullQueue)->getJobExpiration(new RetryForAttributeJobUsingMinutes)
        );

        $this->assertSame(
            CarbonImmutable::now()->addHours(4)->getTimestamp(),
            (new NullQueue)->getJobExpiration(new RetryForAttributeJobUsingHours)
        );
    }

    public function testJobExpirationIsInheritedFromAParentClassAttribute(): void
    {
        $this->assertSame(
            CarbonImmutable::now()->addSeconds(90)->getTimestamp(),
            (new NullQueue)->getJobExpiration(new RetryForAttributeChildJob)
        );
    }

    public function testRetryUntilMethodTakesPrecedenceOverTheAttribute(): void
    {
        $this->assertSame(
            CarbonImmutable::now()->addSeconds(5)->getTimestamp(),
            (new NullQueue)->getJobExpiration(new RetryForAttributeJobWithRetryUntilMethod)
        );
    }

    public function testRetryUntilPropertyTakesPrecedenceOverTheAttribute(): void
    {
        $job = new RetryForAttributeJob;
        $job->retryUntil = 1234567890;

        $this->assertSame(1234567890, (new NullQueue)->getJobExpiration($job));
    }

    public function testJobExpirationIsNullWithoutTheAttribute(): void
    {
        $this->assertNull((new NullQueue)->getJobExpiration(new RetryForAttributeJobWithoutAttribute));
    }

    public function testQueuedMailableUsesTheAttribute(): void
    {
        $job = new SendQueuedMailable(new RetryForAttributeMailable);

        $this->assertEquals(CarbonImmutable::now()->addSeconds(90), $job->retryUntil());
        $this->assertSame(
            CarbonImmutable::now()->addSeconds(90)->getTimestamp(),
            (new NullQueue)->getJobExpiration($job)
        );
    }

    public function testQueuedNotificationUsesTheAttribute(): void
    {
        $job = new SendQueuedNotifications('notifiables', new RetryForAttributeNotification);

        $this->assertEquals(CarbonImmutable::now()->addSeconds(90), $job->retryUntil());
        $this->assertSame(
            CarbonImmutable::now()->addSeconds(90)->getTimestamp(),
            (new NullQueue)->getJobExpiration($job)
        );
    }

    public function testQueuedListenerUsesTheAttribute(): void
    {
        $dispatcher = new Dispatcher;
        $queue = new QueueFake(new Container);

        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen('some.event', RetryForAttributeListener::class . '@handle');
        $dispatcher->dispatch('some.event', ['foo']);

        $queue->assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            return $job->retryUntil == CarbonImmutable::now()->addSeconds(90);
        });
    }
}

#[RetryFor(90)]
class RetryForAttributeJob
{
    public ?int $retryUntil = null;
}

#[RetryFor(minutes: 10)]
class RetryForAttributeJobUsingMinutes
{
}

#[RetryFor(hours: 4)]
class RetryForAttributeJobUsingHours
{
}

class RetryForAttributeChildJob extends RetryForAttributeJob
{
}

#[RetryFor(90)]
class RetryForAttributeJobWithRetryUntilMethod
{
    /**
     * Get the time at which the job should stop retrying.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addSeconds(5);
    }
}

class RetryForAttributeJobWithoutAttribute
{
}

#[RetryFor(90)]
class RetryForAttributeMailable extends Mailable
{
}

#[RetryFor(90)]
class RetryForAttributeNotification extends Notification
{
}

#[RetryFor(90)]
class RetryForAttributeListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(): void
    {
    }
}
