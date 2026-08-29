<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event as ScheduledEvent;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Contracts\Container\Container;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\WorkerStopReason;
use Hypervel\Sentry\Features\ConsoleIntegration as ConsoleFeature;
use Hypervel\Sentry\Features\ConsoleSchedulingFeature;
use Hypervel\Sentry\Features\QueueFeature;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\SentryConfig;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Logs\Logs;
use Sentry\Metrics\TraceMetrics;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Symfony\Component\Console\Input\ArrayInput;

class FlushLifecycleTest extends TestCase
{
    public function testFlushPublishesBufferedTelemetryBeforeFlushingTheTransport(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')
            ->times(3)
            ->andReturn(new Options([
                'enable_logs' => true,
                'enable_metrics' => true,
            ]));
        $client->shouldReceive('captureEvent')
            ->once()
            ->with(
                m::on(static fn (Event $event): bool => $event->getType() === EventType::logs()),
                null,
                m::type(Scope::class),
            )
            ->ordered()
            ->andReturn(null);
        $client->shouldReceive('captureEvent')
            ->once()
            ->with(
                m::on(static fn (Event $event): bool => $event->getType() === EventType::metrics()),
                null,
                m::type(Scope::class),
            )
            ->ordered()
            ->andReturn(null);
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->ordered()
            ->andReturn(new Result(ResultStatus::success()));

        $this->withHub(new Hub($client), static function (): void {
            Logs::getInstance()->info('Buffered log');
            TraceMetrics::getInstance()->count('buffered.metric', 1);

            Integration::flushEvents();
        });
    }

    public function testDrainDerivesAPositiveTimeoutFromTheClient(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')
            ->once()
            ->andReturn(new Options(['http_timeout' => 2.2]));
        $client->shouldReceive('flush')
            ->once()
            ->with(3)
            ->andReturn(new Result(ResultStatus::success()));

        $result = $this->withHub(
            new Hub($client),
            static fn (): Result => Integration::drainEvents(),
        );

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testDrainNormalizesAnExplicitNonPositiveTimeout(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')
            ->never();
        $client->shouldReceive('flush')
            ->once()
            ->with(1)
            ->andReturn(new Result(ResultStatus::success()));

        $result = $this->withHub(
            new Hub($client),
            static fn (): Result => Integration::drainEvents(0),
        );

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testDrainWithoutAClientIsAlreadyComplete(): void
    {
        $result = $this->withHub(
            new Hub,
            static fn (): Result => Integration::drainEvents(),
        );

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testGracefulQueueWorkerStoppingPerformsABoundedDrain(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')
            ->once()
            ->andReturn(new Options(['http_timeout' => 1.2]));
        $client->shouldReceive('flush')
            ->once()
            ->with(2)
            ->andReturn(new Result(ResultStatus::success()));
        $feature = new QueueFeature(m::mock(Container::class));

        $this->withHub(
            new Hub($client),
            static fn () => $feature->handleWorkerStoppingQueueEvent(
                new WorkerStopping(reason: WorkerStopReason::QueueEmpty),
            ),
        );
    }

    public function testImmediateAndMemoryLimitQueueStopsDoNotDrain(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')->never();
        $client->shouldReceive('flush')->never();
        $feature = new QueueFeature(m::mock(Container::class));

        $this->withHub(new Hub($client), static function () use ($feature): void {
            $feature->handleWorkerStoppingQueueEvent(new WorkerStopping(terminatesImmediately: true));
            $feature->handleWorkerStoppingQueueEvent(new WorkerStopping(
                reason: WorkerStopReason::MaxMemoryExceeded,
            ));
        });
    }

    public function testConsoleCompletionFlushesBufferedEventsWithoutABoundedDrain(): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->andReturn(new Result(ResultStatus::success()));
        $client->shouldReceive('getIntegration')
            ->once()
            ->with(Integration::class)
            ->andReturn(null);
        $config = new Repository([
            'sentry' => [
                'dsn' => 'https://public@example.com/1',
                'breadcrumbs' => [
                    'command_info' => false,
                ],
            ],
        ]);
        $sentryConfig = new SentryConfig($config, 'sentry');
        $container = m::mock(Container::class);
        $container->shouldReceive('make')
            ->twice()
            ->with(SentryConfig::class)
            ->andReturn($sentryConfig);
        $feature = new ConsoleFeature($container);

        $this->withHub(new Hub($client), static function () use ($feature): void {
            $command = new FlushLifecycleCommand;
            $input = new ArrayInput([]);

            $feature->beforeHandle(new BeforeHandle($command, $input));
            $feature->afterExecute(new AfterExecute($command, input: $input, exitCode: 0));
        });
    }

    public function testScheduledTaskCompletionFlushesBufferedEventsOnce(): void
    {
        $this->assertScheduledTaskFlushesOnce(static function (
            ConsoleSchedulingFeature $feature,
            ScheduledEvent $event
        ): void {
            $feature->handleScheduledTaskFinished(new ScheduledTaskFinished($event, 0.0));
        });
    }

    public function testScheduledTaskFailureFlushesBufferedEventsOnce(): void
    {
        $this->assertScheduledTaskFlushesOnce(static function (ConsoleSchedulingFeature $feature): void {
            $feature->handleScheduledTaskFailed();
        });
    }

    public function testDuplicateScheduledTaskCompletionDoesNotFlushAgain(): void
    {
        $this->assertScheduledTaskFlushesOnce(static function (
            ConsoleSchedulingFeature $feature,
            ScheduledEvent $event
        ): void {
            $finished = new ScheduledTaskFinished($event, 0.0);

            $feature->handleScheduledTaskFinished($finished);
            $feature->handleScheduledTaskFinished($finished);
        });
    }

    public function testScheduledTaskCompletionFollowedByFailureFlushesOnce(): void
    {
        $this->assertScheduledTaskFlushesOnce(static function (
            ConsoleSchedulingFeature $feature,
            ScheduledEvent $event
        ): void {
            $feature->handleScheduledTaskFinished(new ScheduledTaskFinished($event, 0.0));
            $feature->handleScheduledTaskFailed();
        });
    }

    /**
     * Assert that a scheduled task terminal sequence flushes exactly once.
     *
     * @param callable(ConsoleSchedulingFeature, ScheduledEvent): void $terminal
     */
    private function assertScheduledTaskFlushesOnce(callable $terminal): void
    {
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')
            ->once()
            ->andReturn(new Options);
        $client->shouldReceive('captureEvent')->never();
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->andReturn(new Result(ResultStatus::success()));

        $feature = new ConsoleSchedulingFeature(m::mock(Container::class));
        $event = (new ScheduledEvent(m::mock(EventMutex::class)))->description('Scheduled task');

        $this->withHub(new Hub($client), static function () use ($feature, $event, $terminal): void {
            $feature->handleScheduledTaskStarting(new ScheduledTaskStarting($event));
            $terminal($feature, $event);
        });
    }

    /**
     * Run a callback with an isolated SDK Hub.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withHub(HubInterface $hub, callable $callback): mixed
    {
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub($hub);

        try {
            return $callback();
        } finally {
            SentrySdk::setCurrentHub($previousHub);
        }
    }
}

class FlushLifecycleCommand extends Command
{
    protected ?string $signature = 'test:command';

    public function handle(): void
    {
    }
}
