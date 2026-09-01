<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Closure;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExportScheduler;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Coroutine\CanceledException;

class ExportSchedulerTest extends TestCase
{
    public function testUsesOneTimerAtTheSmallestCadenceAndFlushesOnlyDueSignals(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $manager->shouldReceive('flushSignals')->once()->with(['logs'])->andReturnTrue();
        $manager->shouldReceive('flushSignals')->once()->with(['traces', 'logs'])->andReturnTrue();
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
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);
        $manager->shouldReceive('flushSignals')
            ->once()
            ->with(['traces', 'logs'])
            ->andReturnUsing(function () use ($scheduler): bool {
                $scheduler->time = 12500;

                return true;
            });
        $manager->shouldReceive('flushSignals')->once()->with(['logs'])->andReturnTrue();
        $scheduler->start();

        $scheduler->time = 5000;
        $timer->fire();
        $scheduler->time = 13000;
        $timer->fire();

        $scheduler->stop();
    }

    public function testPeriodicContentionSkipsWithoutReportingProviderFailure(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $manager->shouldReceive('flushSignals')->once()->with(['logs'])->andReturnNull();
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
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $manager->shouldReceive('flushSignals')->once()->with(['logs'])->andThrow($cancellation);
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
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($configuration);
        $timer = new ExportSchedulerTimer;
        $scheduler = new TestExportScheduler($manager, $timer);

        $scheduler->start();

        $this->assertFalse($scheduler->isRunning());
        $this->assertNull($timer->callback);
    }

    public function testClosingTickShutsDownProvidersAndStopsTheTimer(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $manager->shouldReceive('shutdown')->once()->andReturnTrue();
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
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $manager->shouldReceive('shutdown')->once()->andThrow($cancellation);
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
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $manager->shouldReceive('configuration')->once()->andReturn($this->configuration());
        $manager->shouldReceive('shutdown')->once()->andReturnTrue();
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

        return 1;
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
