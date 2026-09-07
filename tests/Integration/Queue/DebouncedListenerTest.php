<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\DebouncedListenerTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
class DebouncedListenerTest extends QueueTestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('cache.default', 'database');
        $config->set('queue.default', env('QUEUE_CONNECTION', 'database'));
    }

    public function testSupersededDebouncedListenerIsSkipped(): void
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['sync', 'beanstalkd']);

        DebouncedTestListener::$handledValues = [];

        Event::listen(DebouncedTestEvent::class, DebouncedTestListener::class);

        Event::dispatch(new DebouncedTestEvent('entity-1', 'first'));
        Event::dispatch(new DebouncedTestEvent('entity-1', 'second'));

        $this->travelTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true], 2);

        $this->assertSame(['second'], DebouncedTestListener::$handledValues);
    }

    public function testMaxDebounceWaitStartsOverAfterTheDebouncedListenerRuns(): void
    {
        $this->markTestSkippedWhenUsingQueueDrivers(['sync', 'beanstalkd']);

        DebouncedWithMaxWaitListener::$handledValues = [];

        Event::listen(DebouncedTestEvent::class, DebouncedWithMaxWaitListener::class);

        Event::dispatch(new DebouncedTestEvent('entity-1', 'first'));

        $this->travelTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertSame(['first'], DebouncedWithMaxWaitListener::$handledValues);

        // Dispatch again at t=92, long after the first listener stopped waiting.
        $this->travelTo(CarbonImmutable::now()->addSeconds(61));

        Event::dispatch(new DebouncedTestEvent('entity-1', 'second'));

        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertSame(['first'], DebouncedWithMaxWaitListener::$handledValues);

        $this->travelTo(CarbonImmutable::now()->addSeconds(31));
        $this->runQueueWorkerCommand(['--once' => true]);

        $this->assertSame(['first', 'second'], DebouncedWithMaxWaitListener::$handledValues);
    }
}

class DebouncedTestEvent
{
    public function __construct(public string $entityId, public string $value)
    {
    }
}

#[DebounceFor(30)]
class DebouncedTestListener implements ShouldQueue
{
    public static array $handledValues = [];

    public function debounceId(DebouncedTestEvent $event): string
    {
        return $event->entityId;
    }

    public function handle(DebouncedTestEvent $event): void
    {
        static::$handledValues[] = $event->value;
    }
}

#[DebounceFor(30, maxWait: 60)]
class DebouncedWithMaxWaitListener implements ShouldQueue
{
    public static array $handledValues = [];

    public function debounceId(DebouncedTestEvent $event): string
    {
        return $event->entityId;
    }

    public function handle(DebouncedTestEvent $event): void
    {
        static::$handledValues[] = $event->value;
    }
}
