<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Database\Schema\Builder;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\Facades\Schema;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class QueueConnectionIsReachable extends Diagnostic
{
    public string $name = 'Queue connects';

    public string $group = 'queue';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default queue connection configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default queue connection [{connection}].',
                remediation: 'Check QUEUE_CONNECTION and the backing queue service configuration.',
            )->link(Link::docs('queues')),
            'reachable' => 'The application can reach the default queue connection.',
            'degraded' => Message::make(
                summary: 'The queue has reachable connections but reduced failover capacity.',
                remediation: 'Repair the unhealthy queue connections listed below.',
            )->link(Link::docs('queues')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connection = Configured::string('queue.default');

        if ($connection === null) {
            return $this->skip('not-configured');
        }

        try {
            $connections = ActiveDrivers::queueConnections($connection);

            if ($connections === []) {
                throw new RuntimeException('The configured queue has no concrete connections to probe.');
            }

            $unhealthy = [];

            foreach ($connections as $name) {
                try {
                    $this->probe($name, $this->configuration($name));
                } catch (CanceledException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $unhealthy[] = "[{$name}]: {$exception->getMessage()}";
                }
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fail('unreachable', ['connection' => $connection])
                ->withDetails($e->getMessage());
        }

        if (count($unhealthy) === count($connections)) {
            return $this->fail('unreachable', ['connection' => $connection])->withDetails(implode(PHP_EOL, $unhealthy));
        }

        if ($unhealthy !== []) {
            return $this->warn('degraded')->withDetails(implode(PHP_EOL, $unhealthy));
        }

        return $this->pass('reachable');
    }

    /**
     * Probe the configured queue connection.
     *
     * @param array<string, mixed> $configuration
     */
    private function probe(string $connection, array $configuration): void
    {
        match ($configuration['driver'] ?? null) {
            'sync' => null,
            'database' => $this->probeDatabaseQueue($configuration),
            'redis' => $this->probeRedisQueue($configuration),
            'sqs', 'beanstalkd' => Queue::connection($connection)->size(),
            default => Queue::connection($connection),
        };
    }

    /**
     * Probe a database-backed queue.
     *
     * @param array<string, mixed> $configuration
     */
    private function probeDatabaseQueue(array $configuration): void
    {
        $table = $configuration['table'] ?? null;

        if (! is_string($table) || $table === '') {
            throw new RuntimeException('The database queue table is not configured.');
        }

        if (! $this->schema($configuration)->hasTable($table)) {
            throw new RuntimeException(sprintf('The [%s] queue table does not exist.', $table));
        }
    }

    /**
     * Probe a Redis-backed queue.
     *
     * @param array<string, mixed> $configuration
     */
    private function probeRedisQueue(array $configuration): void
    {
        $connection = $configuration['connection'] ?? 'default';

        Redis::connection($connection)->ping();
    }

    /**
     * Get the queue connection configuration.
     *
     * @return array<string, mixed>
     */
    private function configuration(string $connection): array
    {
        if ($connection === 'null') {
            return ['driver' => 'null'];
        }

        $configuration = config("queue.connections.{$connection}");

        if (! is_array($configuration)) {
            throw new RuntimeException(sprintf('The [%s] queue connection is not configured.', $connection));
        }

        return $configuration;
    }

    /**
     * Get the schema builder for the queue connection.
     *
     * @param array<string, mixed> $configuration
     */
    private function schema(array $configuration): Builder
    {
        $connection = $configuration['connection'] ?? null;

        return Schema::connection($connection);
    }
}
