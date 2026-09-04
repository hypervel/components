<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Coordinator\Timer;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ExportScheduler
{
    use LogsMessagesTrait;

    /** @var array<string, array{string, string}> */
    protected const array CADENCES = [
        'traces' => ['traces', 'schedule_delay'],
        'logs' => ['logs', 'schedule_delay'],
        'metrics' => ['metrics', 'export_interval'],
    ];

    protected ?int $timerId = null;

    protected int $generation = 0;

    /** @var array<string, int> */
    protected array $intervals = [];

    /** @var array<string, float> */
    protected array $nextDueAt = [];

    /**
     * Create an export scheduler.
     */
    public function __construct(
        protected OpenTelemetryManager $manager,
        protected Timer $timer,
    ) {
    }

    /**
     * Start the single export timer for every active signal.
     */
    public function start(): void
    {
        if ($this->timerId !== null || ! $this->manager->isBound()) {
            return;
        }

        $configuration = $this->manager->configuration();

        foreach (self::CADENCES as $signal => [$section, $key]) {
            if ($configuration[$section]['exporter'] !== 'none') {
                $this->intervals[$signal] = $configuration[$section][$key];
            }
        }

        if ($this->intervals === []) {
            return;
        }

        $now = $this->now();

        foreach ($this->intervals as $signal => $interval) {
            $this->nextDueAt[$signal] = $now + $interval;
        }

        try {
            $this->timerId = $this->timer->tick(
                min($this->intervals) / 1000,
                fn (bool $isClosing): ?string => $this->handleTick($isClosing),
            );
        } catch (Throwable $exception) {
            $this->reset();

            throw $exception;
        }
    }

    /**
     * Stop the export timer without closing providers.
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            $this->timer->clear($this->timerId);
        }

        $this->reset();
    }

    /**
     * Stop the export timer and close the provider graph.
     */
    public function shutdown(): bool
    {
        $this->stop();

        return $this->manager->shutdown();
    }

    /**
     * Determine whether the export timer is active.
     */
    public function isRunning(): bool
    {
        return $this->timerId !== null;
    }

    /**
     * Process one timer wake-up.
     */
    protected function handleTick(bool $isClosing): ?string
    {
        if ($isClosing) {
            $this->reset();

            try {
                if (! $this->manager->shutdown()) {
                    self::logError('OpenTelemetry provider shutdown did not complete successfully.');
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                self::logError('OpenTelemetry provider shutdown failed.', ['exception' => $exception]);
            }

            return Timer::STOP;
        }

        // Snapshot before the flush can yield: stop() may reset cadence state and
        // invalidate this run while the export is suspended.
        $generation = $this->generation;
        $now = $this->now();
        $dueSignals = [];

        foreach ($this->nextDueAt as $signal => $nextDueAt) {
            if ($nextDueAt <= $now) {
                $dueSignals[] = $signal;
            }
        }

        if ($dueSignals === []) {
            return null;
        }

        try {
            if ($this->manager->flushSignals($dueSignals) === false) {
                self::logError('OpenTelemetry periodic export did not complete successfully.');
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            self::logError('OpenTelemetry periodic export failed.', ['exception' => $exception]);
        } finally {
            if ($generation === $this->generation) {
                $finishedAt = $this->now();

                foreach ($dueSignals as $signal) {
                    $interval = $this->intervals[$signal];
                    $missedIntervals = (int) floor(
                        max(0.0, $finishedAt - $this->nextDueAt[$signal]) / $interval,
                    ) + 1;
                    $this->nextDueAt[$signal] += $missedIntervals * $interval;
                }
            }
        }

        return null;
    }

    /**
     * Return monotonic milliseconds.
     */
    protected function now(): float
    {
        return hrtime(true) / 1_000_000;
    }

    /**
     * Reset scheduler-owned state.
     */
    protected function reset(): void
    {
        ++$this->generation;
        $this->timerId = null;
        $this->intervals = [];
        $this->nextDueAt = [];
    }
}
