<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Configured;
use PDO;
use Swoole\Coroutine\CanceledException;
use Throwable;

class DatabaseConnectionIsReachable extends Diagnostic
{
    private const int CONNECTION_TIMEOUT_SECONDS = 2;

    public string $name = 'Database connects';

    public string $group = 'database';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default database connection configured.',
            'connection-missing' => 'The default database connection [{connection}] is not configured.',
            'unreachable' => Message::make(
                summary: 'The application cannot connect to the default database connection [{connection}].',
                remediation: 'Check DB_CONNECTION and the database credentials in your environment file.',
            )->link(Link::docs('database', 'configuration')),
            'reachable' => 'The application can connect to the default database connection.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connection = Configured::string('database.default');

        if ($connection === null) {
            return $this->skip('not-configured');
        }

        $configuration = $this->configuration($connection);

        if ($configuration === null) {
            return $this->skip('connection-missing', ['connection' => $connection]);
        }

        try {
            $this->probe($connection, $configuration);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fail('unreachable', ['connection' => $connection])
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe the database connection.
     *
     * @param array<string, mixed> $configuration
     */
    private function probe(string $connection, array $configuration): void
    {
        /** @var ConnectionFactory $factory */
        $factory = app('db.factory');
        $database = $factory->make(
            $this->withConnectionTimeout($configuration),
            $connection,
        );

        $failure = null;

        try {
            $database->getServerVersion();
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            try {
                $database->disconnect();
            } catch (CanceledException $exception) {
                if (! $failure instanceof CanceledException) {
                    $failure = $exception;
                }
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Get the default database connection configuration.
     *
     * @return null|array<string, mixed>
     */
    private function configuration(string $connection): ?array
    {
        $configuration = config("database.connections.{$connection}");

        if (! is_array($configuration)) {
            return null;
        }

        return app('db.factory')->parseConfig($configuration, $connection);
    }

    /**
     * Add conservative connection timeouts to the transient probe configuration.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function withConnectionTimeout(array $configuration): array
    {
        $options = $configuration['options'] ?? [];

        if (! is_array($options)) {
            $options = [];
        }

        $options[PDO::ATTR_TIMEOUT] ??= self::CONNECTION_TIMEOUT_SECONDS;

        $configuration['options'] = $options;

        return $configuration;
    }
}
