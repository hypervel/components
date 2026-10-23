<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Queue\Worker;
use Hypervel\Support\Sleep;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class OutboxWorker
{
    /**
     * Create a worker with invocation-local admission and shutdown state.
     */
    public function __construct(
        protected DatabaseOutboxRepository $repository,
        protected OutboxRelay $relay,
        protected CacheFactory $cache,
        protected ExceptionHandler $exceptions,
        protected SignalRegistry $signals,
    ) {
    }

    /**
     * Relay committed work until the requested limit or shutdown condition is reached.
     */
    public function run(?string $database, OutboxWorkerOptions $options): int
    {
        $databases = $this->repository->getDatabases();

        if ($database !== null) {
            if (! in_array($database, $databases, true)) {
                throw new InvalidArgumentException("Outbox database [{$database}] is not configured in queue.outbox.databases.");
            }

            $databases = [$database];
        }

        $quit = false;
        $paused = false;
        $signalHandler = static function (int $signal) use (&$quit, &$paused): void {
            match ($signal) {
                SIGUSR2 => $paused = true,
                SIGCONT => $paused = false,
                default => $quit = true,
            };
        };
        $this->signals->register($signalHandler, [SIGTERM, SIGINT, SIGQUIT, SIGUSR2, SIGCONT], $signalHandler);
        $concurrent = new WaitConcurrent($options->concurrency);
        $taskFailure = null;
        $failure = null;
        $status = Worker::EXIT_SUCCESS;

        try {
            $startedAt = microtime(true);
            $lastRestart = $options->once ? null : $this->restartTimestamp();
            $admitted = 0;
            $visited = 0;
            $emptyVisits = 0;
            $retryAt = [];
            $sourceCount = count($databases);

            while (! $quit && $taskFailure === null) {
                if (memory_get_usage(true) / 1024 / 1024 >= $options->memory) {
                    $status = Worker::EXIT_MEMORY_LIMIT;
                    break;
                }

                if (($options->maxJobs > 0 && $admitted >= $options->maxJobs)
                    || ($options->maxTime > 0 && microtime(true) - $startedAt >= $options->maxTime)
                    || ($options->once && $visited >= $sourceCount)
                    || (! $options->once && $lastRestart !== $this->restartTimestamp())) {
                    break;
                }

                if ($paused) {
                    Sleep::usleep((int) ($options->sleep * 1_000_000));
                    continue;
                }

                if ($concurrent->isFull()) {
                    $concurrent->waitForAvailableSlot($options->sleep);
                    continue;
                }

                $source = $databases[$visited++ % $sourceCount];
                $limit = $options->maxJobs > 0 ? min($options->batch, $options->maxJobs - $admitted) : $options->batch;
                $messages = [];

                if (! isset($retryAt[$source]) || microtime(true) >= $retryAt[$source]) {
                    $messages = $this->claim($source, $limit);

                    if ($messages === null) {
                        $retryAt[$source] = microtime(true) + $options->sleep;
                    } else {
                        unset($retryAt[$source]);
                    }
                }

                if ($messages) {
                    $emptyVisits = 0;
                    $admitted += count($messages);

                    if ($options->once) {
                        $this->relay->deliver($source, $messages);
                    } else {
                        $concurrent->create(function () use ($source, $messages, $options, &$taskFailure): void {
                            try {
                                if (! $this->relay->deliver($source, $messages)) {
                                    Sleep::usleep((int) ($options->sleep * 1_000_000));
                                }
                            } catch (CanceledException $exception) {
                                throw $exception;
                            } catch (Throwable $exception) {
                                $taskFailure ??= $exception;
                            }
                        });
                    }
                } elseif (++$emptyVisits >= $sourceCount) {
                    if ($options->stopWhenEmpty) {
                        break;
                    }

                    if (! $options->once) {
                        Sleep::usleep((int) ($options->sleep * 1_000_000));
                    }

                    $emptyVisits = 0;
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;

            if ($exception instanceof CanceledException) {
                $concurrent->cancel();
            }
        } finally {
            try {
                $this->join($concurrent);
            } catch (CanceledException $exception) {
                if (! $failure instanceof CanceledException) {
                    $failure = $exception;
                }
            } finally {
                $this->signals->unregister($signalHandler);
            }
        }

        if ($failure !== null || $taskFailure !== null) {
            throw $failure ?? $taskFailure;
        }

        return $status;
    }

    /**
     * Report a failed claim after its database-owning coroutine has ended.
     *
     * @return null|list<OutboxMessage> null indicates a source failure
     */
    protected function claim(string $database, int $limit): ?array
    {
        try {
            return $this->relay->claim($database, $limit);
        } catch (CanceledException|ChildCancellationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            (new Waiter(-1))->wait(
                fn () => $this->exceptions->report($exception, ['outbox_database' => $database]),
                waitForChildTermination: true,
            );

            return null;
        }
    }

    /**
     * Read the restart signal without retaining a cache connection on the coordinator.
     */
    protected function restartTimestamp(): ?int
    {
        return (new Waiter(-1))->wait(function (): ?int {
            $timestamp = $this->cache->store()->get(Worker::RESTART_SIGNAL_CACHE_KEY);

            return $timestamp === null ? null : (int) $timestamp;
        }, waitForChildTermination: true);
    }

    /**
     * Join every owned task before propagating cancellation, including repeated cancellation during cleanup.
     */
    protected function join(WaitConcurrent $concurrent): void
    {
        $cancellation = null;

        do {
            try {
                $finished = $concurrent->wait();
            } catch (CanceledException $exception) {
                $cancellation ??= $exception;
                $finished = false;
            }
        } while (! $finished);

        if ($cancellation !== null) {
            throw $cancellation;
        }
    }
}
