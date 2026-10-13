<?php

declare(strict_types=1);

namespace App\Bench;

use Hypervel\Container\Container;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Sentry\State\HubInterface;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Report worker state on a separate port without entering the HTTP kernel, so Sentry never sees it.
 */
class StatsCallback
{
    /**
     * Write the worker's memory, coroutine and Sentry transport state as JSON.
     */
    public function onRequest(Request $request, Response $response): void
    {
        $stats = [
            'pid' => getmypid(),
            'memory' => memory_get_usage(),
            'memory_real' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(),
            'peak_real' => memory_get_peak_usage(true),
            'rss_kb' => $this->rss(),
            // Includes the coroutine serving this request; quiescence compares it with the same reading at idle.
            'coroutines' => Coroutine::stats()['coroutine_num'],
            'gc' => gc_status(),
            'opcache' => [
                'enabled' => (opcache_get_status(false) ?: [])['opcache_enabled'] ?? false,
                'jit' => (opcache_get_status(false) ?: [])['jit']['on'] ?? false,
            ],
            'sentry' => $this->sentry(),
        ];

        if (isset($request->get['reset_peak'])) {
            memory_reset_peak_usage();
        }

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($stats));
    }

    /**
     * Read the worker's resident set size in kilobytes.
     */
    protected function rss(): int
    {
        preg_match('/^VmRSS:\s+(\d+)/m', file_get_contents('/proc/self/status'), $matches);

        return (int) $matches[1];
    }

    /**
     * Read the pooled transport's state when Sentry has a client.
     *
     * @return null|array<string, mixed>
     */
    protected function sentry(): ?array
    {
        $container = Container::getInstance();

        if (! interface_exists(HubInterface::class) || ! $container->resolved(HubInterface::class)) {
            return null;
        }

        $transport = $container->make(HubInterface::class)->getClient()?->getTransport();

        if (! $transport instanceof HttpPoolTransport) {
            return ['transport' => $transport === null ? null : $transport::class];
        }

        [$pool, $group] = (fn (): array => [$this->pool, $this->group])->call($transport);

        return [
            'transport' => $transport::class,
            'managed' => $pool->getManagedCount(),
            'borrowed' => $pool->getBorrowedCount(),
            'idle' => $pool->getIdleCount(),
            'waiting' => $pool->getWaitingCount(),
            'pending_sends' => $group->count(),
        ];
    }
}
