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
use Hypervel\Sentry\Features\ConsoleSchedulingIntegration;
use Hypervel\Sentry\Features\QueueIntegration;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\SentryConfig;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Integration\OTLPIntegration;
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
    use VerifiesDoubles;

    public function testDrainPublishesBufferedTelemetryBeforeTheBoundedWait(): void
    {
        $client = Double::for(ClientInterface::class)->strict();
        // Once installed, Sentry's process-wide OTLP callback looks up the integration on the current client.
        $client->allows('getIntegration')->with(OTLPIntegration::class)->returns(null);
        $client->expects('getOptions')
            ->times(3)
            ->returns(new Options);
        $client->expects('captureEvent')
            ->with(
                Argument::satisfies(static fn (Event $event): bool => $event->getType() === EventType::logs()),
                null,
                Argument::type(Scope::class),
            )
            ->ordered()
            ->returns(null);
        $client->expects('captureEvent')
            ->with(
                Argument::satisfies(static fn (Event $event): bool => $event->getType() === EventType::metrics()),
                null,
                Argument::type(Scope::class),
            )
            ->ordered()
            ->returns(null);
        $client->expects('flush')
            ->with(Argument::none())
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('flush')
            ->with(1)
            ->ordered()
            ->returns(new Result(ResultStatus::success()));

        $this->withHub(new Hub($client), static function (): void {
            Logs::getInstance()->info('Buffered log');
            TraceMetrics::getInstance()->count('buffered.metric', 1);

            Integration::drainEvents(1);
        });
    }

    public function testDrainDerivesAPositiveTimeoutFromTheClient(): void
    {
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')
            ->returns(new Options(['http_timeout' => 2.2]));
        $client->expects('flush')
            ->with(Argument::none())
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('flush')
            ->with(3)
            ->ordered()
            ->returns(new Result(ResultStatus::success()));

        $result = $this->withHub(
            new Hub($client),
            static fn (): Result => Integration::drainEvents(),
        );

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testDrainNormalizesAnExplicitNonPositiveTimeout(): void
    {
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')
            ->never();
        $client->expects('flush')
            ->with(Argument::none())
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('flush')
            ->with(1)
            ->ordered()
            ->returns(new Result(ResultStatus::success()));

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
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')
            ->returns(new Options(['http_timeout' => 1.2]));
        $client->expects('flush')
            ->with(Argument::none())
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $client->expects('flush')
            ->with(2)
            ->ordered()
            ->returns(new Result(ResultStatus::success()));
        $feature = new QueueIntegration(Double::for(Container::class, override: true)->strict()->instance());

        $this->withHub(
            new Hub($client),
            static fn () => $feature->handleWorkerStoppingQueueEvent(
                new WorkerStopping(reason: WorkerStopReason::QueueEmpty),
            ),
        );
    }

    public function testImmediateAndMemoryLimitQueueStopsDoNotDrain(): void
    {
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')->never();
        $client->expects('flush')->never();
        $feature = new QueueIntegration(Double::for(Container::class, override: true)->strict()->instance());

        $this->withHub(new Hub($client), static function () use ($feature): void {
            $feature->handleWorkerStoppingQueueEvent(new WorkerStopping(terminatesImmediately: true));
            $feature->handleWorkerStoppingQueueEvent(new WorkerStopping(
                reason: WorkerStopReason::MaxMemoryExceeded,
            ));
        });
    }

    public function testConsoleCompletionLeavesGlobalTelemetryForApplicationTermination(): void
    {
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('flush')->never();
        $client->expects('getIntegration')
            ->with(Integration::class)
            ->returns(null);
        $config = new Repository([
            'sentry' => [
                'dsn' => 'https://public@example.com/1',
                'breadcrumbs' => [
                    'command_info' => false,
                ],
            ],
        ]);
        $sentryConfig = new SentryConfig($config, 'sentry');
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')
            ->times(2)
            ->with(SentryConfig::class)
            ->returns($sentryConfig);
        $feature = new ConsoleFeature($container->instance());

        $this->withHub(new Hub($client), static function () use ($feature): void {
            $command = new FlushLifecycleCommand;
            $input = new ArrayInput([]);

            $feature->beforeHandle(new BeforeHandle($command, $input));
            $feature->afterExecute(new AfterExecute($command, null, $input, 0));
        });
    }

    public function testScheduledTaskCompletionFlushesAtExecutionEnd(): void
    {
        $this->assertScheduledTaskFlushesAtExecutionEnd(static function (
            ConsoleSchedulingIntegration $feature,
            ScheduledEvent $event
        ): void {
            $feature->handleScheduledTaskFinished(new ScheduledTaskFinished($event, 0.0));
        });
    }

    public function testScheduledTaskFailureFlushesAtExecutionEnd(): void
    {
        $this->assertScheduledTaskFlushesAtExecutionEnd(static function (ConsoleSchedulingIntegration $feature): void {
            $feature->handleScheduledTaskFailed();
        });
    }

    public function testDuplicateScheduledTaskCompletionDoesNotFlushAgain(): void
    {
        $this->assertScheduledTaskFlushesAtExecutionEnd(static function (
            ConsoleSchedulingIntegration $feature,
            ScheduledEvent $event
        ): void {
            $finished = new ScheduledTaskFinished($event, 0.0);

            $feature->handleScheduledTaskFinished($finished);
            $feature->handleScheduledTaskFinished($finished);
        });
    }

    public function testScheduledTaskCompletionFollowedByFailureFlushesOnce(): void
    {
        $this->assertScheduledTaskFlushesAtExecutionEnd(static function (
            ConsoleSchedulingIntegration $feature,
            ScheduledEvent $event
        ): void {
            $feature->handleScheduledTaskFinished(new ScheduledTaskFinished($event, 0.0));
            $feature->handleScheduledTaskFailed();
        });
    }

    /**
     * Assert that a scheduled task terminal sequence flushes at execution end.
     *
     * @param callable(ConsoleSchedulingIntegration, ScheduledEvent): void $terminal
     */
    private function assertScheduledTaskFlushesAtExecutionEnd(callable $terminal): void
    {
        $flushed = false;
        $client = Double::for(ClientInterface::class)->strict();
        $client->allows('getOptions')->returns(new Options);
        $client->expects('captureEvent')->never();
        $client->expects('flush')
            ->with(null)
            ->resolves(static function () use (&$flushed): Result {
                $flushed = true;

                return new Result(ResultStatus::success());
            });

        $feature = new ConsoleSchedulingIntegration(Double::for(Container::class, override: true)->strict()->instance());
        $event = (new ScheduledEvent(Double::for(EventMutex::class)->strict()))->description('Scheduled task');
        $hub = new Hub($client);
        $storage = new CoroutineRuntimeContextStorage;
        $previousHub = SentrySdk::getCurrentHub();

        SentrySdk::setRuntimeContextStorage($storage);
        SentrySdk::setCurrentHub($hub);
        SentrySdk::startContext($hub);

        try {
            $feature->handleScheduledTaskStarting(new ScheduledTaskStarting($event));
            $terminal($feature, $event);

            $this->assertFalse($flushed);

            SentrySdk::endContext();

            $this->assertTrue($flushed);
        } finally {
            SentrySdk::endContext();
            SentrySdk::setCurrentHub($previousHub);
        }
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

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
    }
}
