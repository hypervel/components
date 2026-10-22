<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Facades\Redis;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisConnectionsAreReachable extends Diagnostic
{
    public string $name = 'Redis connects';

    public string $group = 'cache';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application is not using Redis-backed cache, queue, or session storage.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach every active Redis connection.',
                remediation: 'Check Redis host, port, credentials, and client configuration.',
            )->link(Link::docs('redis', 'configuration')),
            'reachable' => 'The application can reach every active Redis connection.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connections = ActiveDrivers::redisConnections();

        if ($connections === []) {
            return $this->skip('not-configured');
        }

        $failures = [];

        foreach ($connections as $connection) {
            try {
                Redis::connection($connection)->ping();
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $e) {
                $failures[$connection] = $e->getMessage();
            }
        }

        if ($failures !== []) {
            return $this->fail('unreachable')
                ->withDetails(Details::failures($failures));
        }

        return $this->pass('reachable');
    }
}
