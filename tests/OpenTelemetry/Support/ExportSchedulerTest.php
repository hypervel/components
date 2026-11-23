<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Closure;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExportScheduler;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Swoole\Coroutine\CanceledException;

class ExportSchedulerTest extends TestCase
{
    use VerifiesDoubles;

    public function testUsesOneTimerAtTheSmallestCadenceAndFlushesOnlyDueSignals(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $manager->expects('flushSignals')->with(['logs'])->returns(true);
        $manager->expects('flushSignals')->with(['traces', 'logs'])->returns(true);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);

        $scheduler->start();
        $scheduler->start();

        $this->assertSame(1.0, $timer->interval);
        $this->assertTrue($scheduler->isRunning());

        $scheduler->time = 1000;
        $this->assertNull($timer->fire());
        $scheduler->time = 5000;
        $this->assertNull($timer->fire());

        $scheduler->stop();

        $this->assertSame([1], $timer->cleared);
        $this->assertFalse($scheduler->isRunning());
    }

    public function testAdvancesDueTimesPastASlowExportWithoutCatchUpBursts(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $manager->expects('flushSignals')
            ->with(['traces', 'logs'])
            ->resolves(function () use ($scheduler): bool {
                $scheduler->time = 12500;

                return true;
            });
        $manager->expects('flushSignals')->with(['logs'])->returns(true);
        $scheduler->start();

        $scheduler->time = 5000;
        $timer->fire();
        $scheduler->time = 13000;
        $timer->fire();

        $scheduler->stop();
    }

    public function testStopDuringAnInFlightExportDoesNotRescheduleClearedCadence(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $manager->expects('flushSignals')
            ->with(['logs'])
            ->resolves(function () use ($scheduler): bool {
                $scheduler->stop();

                return true;
            });
        $scheduler->start();
        $scheduler->time = 1000;

        $this->assertNull($timer->fire());
        $this->assertFalse($scheduler->isRunning());
        $this->assertSame([1], $timer->cleared);
    }

    public function testStaleExportDoesNotAdvanceRestartedCadence(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->times(2)->returns(true);
        $manager->expects('configuration')->times(2)->returns($this->configuration());
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $flushCalls = 0;
        $manager->expects('flushSignals')
            ->times(2)
            ->with(['logs'])
            ->resolves(function () use ($scheduler, &$flushCalls): bool {
                if (++$flushCalls === 1) {
                    $scheduler->stop();
                    $scheduler->start();
                }

                return true;
            });
        $scheduler->start();
        $scheduler->time = 1000;

        $this->assertNull($timer->fire());
        $this->assertTrue($scheduler->isRunning());
        $this->assertSame([1], $timer->cleared);

        $scheduler->time = 2000;

        $this->assertNull($timer->fire());

        $scheduler->stop();

        $this->assertSame([1, 2], $timer->cleared);
    }

    public function testPeriodicContentionSkipsWithoutReportingProviderFailure(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $manager->expects('flushSignals')->with(['logs'])->returns(null);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $scheduler->start();

        $scheduler->time = 1000;

        $this->assertNull($timer->fire());

        $scheduler->stop();
    }

    public function testPeriodicCancellationEscapesToTheTimerOwner(): void
    {
        $cancellation = new CanceledException;
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $manager->expects('flushSignals')->with(['logs'])->throws($cancellation);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $scheduler->start();
        $scheduler->time = 1000;

        try {
            $timer->fire();
            $this->fail('Expected periodic export cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        } finally {
            $scheduler->stop();
        }
    }

    public function testDoesNotStartATimerWithoutAnActiveSignal(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['exporter'] = 'none';
        $configuration['traces']['exporter'] = 'none';
        $configuration['logs']['exporter'] = 'none';
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($configuration);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);

        $scheduler->start();

        $this->assertFalse($scheduler->isRunning());
        $this->assertNull($timer->callback);
    }

    public function testClosingTickShutsDownProvidersAndStopsTheTimer(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $manager->expects('shutdown')->returns(true);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $scheduler->start();

        $this->assertSame(Timer::STOP, $timer->fire(true));
        $this->assertFalse($scheduler->isRunning());
        $this->assertSame([], $timer->cleared);
    }

    public function testClosingCancellationEscapesToTheTimerOwner(): void
    {
        $cancellation = new CanceledException;
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $manager->expects('shutdown')->throws($cancellation);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $scheduler->start();

        try {
            $timer->fire(true);
            $this->fail('Expected closing export cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertFalse($scheduler->isRunning());
    }

    public function testExplicitShutdownClearsTheTimerBeforeClosingProviders(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(true);
        $manager->expects('configuration')->returns($this->configuration());
        $manager->expects('shutdown')->returns(true);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $scheduler->start();

        $this->assertTrue($scheduler->shutdown());
        $this->assertSame([1], $timer->cleared);
        $this->assertFalse($scheduler->isRunning());
    }

    /**
     * Return signal configuration for scheduler tests.
     *
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        return [
            'metrics' => ['exporter' => 'fixture', 'export_interval' => 60000],
            'traces' => ['exporter' => 'fixture', 'schedule_delay' => 5000],
            'logs' => ['exporter' => 'fixture', 'schedule_delay' => 1000],
        ];
    }
}

class TestExportScheduler extends ExportScheduler
{
    public float $time = 0;

    /**
     * Return the controlled monotonic test time.
     */
    protected function now(): float
    {
        return $this->time;
    }
}

class ExportSchedulerTimer extends Timer
{
    private int $nextId = 0;

    public ?float $interval = null;

    public ?Closure $callback = null;

    /** @var list<int> */
    public array $cleared = [];

    /**
     * Capture a recurring timer callback.
     */
    public function tick(
        float $timeout,
        callable $closure,
        string $identifier = Constants::WORKER_EXIT,
    ): int {
        $this->interval = $timeout;
        $this->callback = $closure(...);

        return ++$this->nextId;
    }

    /**
     * Record a cleared timer.
     */
    public function clear(int $id): void
    {
        $this->cleared[] = $id;
    }

    /**
     * Invoke the captured timer callback.
     */
    public function fire(bool $isClosing = false): mixed
    {
        return ($this->callback)($isClosing);
    }
}
