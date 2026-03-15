<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Transport;

use Hypervel\Context\Context;
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

        $transports = Context::get(self::CONTEXT_TRANSPORTS_KEY, []);
        $transports[] = $transport;
        Context::set(self::CONTEXT_TRANSPORTS_KEY, $transports);

        try {
            return $transport->send($event);
        } catch (Throwable) {
            // Remove from tracked list and release back to pool
            $transports = Context::get(self::CONTEXT_TRANSPORTS_KEY, []);
            if (($key = array_search($transport, $transports, true)) !== false) {
                unset($transports[$key]);
                Context::set(self::CONTEXT_TRANSPORTS_KEY, array_values($transports));
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
        foreach (Context::get(self::CONTEXT_TRANSPORTS_KEY, []) as $transport) {
            $this->pool->release($transport);
        }
        Context::set(self::CONTEXT_TRANSPORTS_KEY, []);

        return new Result(ResultStatus::success());
    }
}
