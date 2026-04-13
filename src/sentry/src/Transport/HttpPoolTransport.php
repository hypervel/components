<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use RuntimeException;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Throwable;

class HttpPoolTransport implements TransportInterface
{
    /**
     * Context key for the per-coroutine list of checked-out transports.
     */
    public const CONTEXT_TRANSPORTS_KEY = '__sentry.transports';

    public function __construct(protected Pool $pool)
    {
    }

    /**
     * Send an event to Sentry via a pooled transport.
     *
     * Checks out a transport from the pool. If the pool is exhausted, the event
     * is silently dropped (backpressure) to avoid blocking the request coroutine.
     * All checked-out transports are tracked per-coroutine and released on close().
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

        $transports = $this->initializeTrackedTransports();
        $transports[] = $transport;
        CoroutineContext::set(self::CONTEXT_TRANSPORTS_KEY, $transports);

        try {
            return $transport->send($event);
        } catch (Throwable) {
            // Remove from tracked list and release back to pool
            $transports = CoroutineContext::get(self::CONTEXT_TRANSPORTS_KEY, []);
            if (($key = array_search($transport, $transports, true)) !== false) {
                unset($transports[$key]);
                CoroutineContext::set(self::CONTEXT_TRANSPORTS_KEY, array_values($transports));
            }
            $this->pool->release($transport);

            return new Result(ResultStatus::failed());
        }
    }

    /**
     * Release all checked-out transports back to the pool.
     *
     * Called by Integration::flushEvents() → Client::flush() at the end
     * of each request lifecycle via FlushEventsMiddleware::terminate().
     */
    public function close(?int $timeout = null): Result
    {
        foreach (CoroutineContext::get(self::CONTEXT_TRANSPORTS_KEY, []) as $transport) {
            $this->pool->release($transport);
        }
        CoroutineContext::set(self::CONTEXT_TRANSPORTS_KEY, []);

        return new Result(ResultStatus::success());
    }

    /**
     * Get the tracked transport list, registering a coroutine defer callback
     * on first access to ensure transports are released even if the coroutine
     * dies without close() being called.
     *
     * @return list<HttpTransport>
     */
    private function initializeTrackedTransports(): array
    {
        $transports = CoroutineContext::get(self::CONTEXT_TRANSPORTS_KEY);

        if (is_array($transports)) {
            return $transports;
        }

        CoroutineContext::set(self::CONTEXT_TRANSPORTS_KEY, []);

        Coroutine::defer(function (): void {
            foreach (CoroutineContext::get(self::CONTEXT_TRANSPORTS_KEY, []) as $transport) {
                $this->pool->release($transport);
            }
            CoroutineContext::set(self::CONTEXT_TRANSPORTS_KEY, []);
        });

        return [];
    }
}
