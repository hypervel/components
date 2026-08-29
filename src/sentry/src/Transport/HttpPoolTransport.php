<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitGroup;
use RuntimeException;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Swoole\Runtime;
use Throwable;

use function Hypervel\Coroutine\run;

class HttpPoolTransport implements TransportInterface
{
    protected WaitGroup $group;

    public function __construct(protected Pool $pool)
    {
        $this->group = new WaitGroup;
    }

    /**
     * Send an event to Sentry via a pooled transport.
     *
     * Checks out a transport from the pool. If the pool is exhausted, the event
     * is silently dropped (backpressure) to avoid blocking the request coroutine.
     */
    public function send(Event $event): Result
    {
        try {
            /** @var HttpTransport $transport */
            $transport = $this->pool->get();
        } catch (RuntimeException) {
            // Pool exhausted — drop event to avoid blocking the request coroutine
            return new Result(ResultStatus::skipped());
        }

        // Capture the generation once: a concurrent drain may replace $this->group,
        // but this send must call done() on the same group it increments.
        $group = $this->group;
        $group->add();

        try {
            $this->createCoroutine(function () use ($event, $group, $transport): void {
                $discard = false;

                try {
                    $transport->send($event);
                } catch (Throwable) {
                    $discard = true;
                } finally {
                    try {
                        if ($discard) {
                            $this->pool->discard($transport);
                        } else {
                            $this->pool->release($transport);
                        }
                    } finally {
                        $group->done();
                    }
                }
            });
        } catch (Throwable) {
            try {
                $this->pool->release($transport);
            } finally {
                $group->done();
            }

            return new Result(ResultStatus::failed());
        }

        return new Result(ResultStatus::success(), $event);
    }

    /**
     * Observe or wait for accepted sends to complete.
     */
    public function close(?int $timeout = null): Result
    {
        if ($timeout === null || $timeout <= 0) {
            return new Result($this->group->count() === 0
                ? ResultStatus::success()
                : ResultStatus::unknown());
        }

        $group = $this->group;
        $this->group = new WaitGroup;

        return new Result($group->wait($timeout)
            ? ResultStatus::success()
            : ResultStatus::unknown());
    }

    /**
     * Close the underlying transport pool.
     */
    public function shutdown(): void
    {
        $this->pool->close();
    }

    /**
     * Create the coroutine that owns a checked-out transport.
     */
    protected function createCoroutine(callable $callback): void
    {
        if (Coroutine::inCoroutine()) {
            Coroutine::create($callback);

            return;
        }

        run($callback, Runtime::getHookFlags());
    }
}
