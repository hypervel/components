<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\PdoConnection;
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

        $name = ConnectionName::parse($connection);
        $configuration = $this->configuration($name->base);

        if ($configuration === null) {
            return $this->skip('connection-missing', ['connection' => $connection]);
        }

        try {
            $failures = $this->probe($name, $configuration);

            if ($failures !== []) {
                return $this->fail('unreachable', ['connection' => $connection])
                    ->withDetails(implode(PHP_EOL, $failures));
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fail('unreachable', ['connection' => $connection])
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe every record the selected connection can use.
     *
     * @param array<string, mixed> $configuration
     * @return list<string>
     */
    private function probe(ConnectionName $name, array $configuration): array
    {
        /** @var ConnectionFactory $factory */
        $factory = app('db.factory');
        $failures = [];

        foreach ($this->probeConfigurations($factory, $name, $configuration) as $label => [$selected, $read]) {
            try {
                $this->probeConnection($factory, $name->base, $selected, $read);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failures[] = ($label === '' ? '' : "[{$label}] ") . $exception->getMessage();
            }
        }

        return $failures;
    }

    /**
     * Select records without changing the factory's extension resolution path.
     *
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    private function probeConfigurations(ConnectionFactory $factory, ConnectionName $name, array $configuration): iterable
    {
        $extension = $factory->getExtension($configuration, $name->base);

        if ($extension !== null || ! $factory->hasReadConfig($configuration)) {
            if ($name->role !== null) {
                $configuration[Connection::READ_WRITE_TYPE_CONFIG_KEY] = $name->role;
            }

            yield '' => [$extension !== null ? $configuration : $this->withConnectionTimeout($configuration), false];

            return;
        }

        foreach ($name->role !== null ? [$name->role] : [ConnectionName::WRITE, ConnectionName::READ] as $role) {
            $records = isset($configuration[$role][0]) ? $configuration[$role] : [$configuration[$role]];

            foreach (array_values($records) as $index => $record) {
                $selected = array_replace($configuration, [$role => $record]);
                $effective = $role === ConnectionName::READ
                    ? $factory->configForRead($selected)
                    : $factory->configForWrite($selected);

                if ($name->isRead()) {
                    $selected = $factory->getExtension($effective, $name->base) !== null
                        ? $effective
                        : $this->withConnectionTimeout($effective);
                } else {
                    // Base split connections resolve read connectors without another extension lookup.
                    $selected[$role]['options'] = $this->withConnectionTimeout($effective)['options'];
                }

                if ($name->role !== null) {
                    $selected[Connection::READ_WRITE_TYPE_CONFIG_KEY] = $name->role;
                }

                yield $role . ' record ' . ($index + 1) => [$selected, $name->role === null && $role === ConnectionName::READ];
            }
        }
    }

    /**
     * Open and release a transient connection through its owning factory.
     */
    private function probeConnection(ConnectionFactory $factory, string $name, array $configuration, bool $read): void
    {
        $database = $factory->make($configuration, $name);

        $failure = null;

        try {
            if ($read) {
                /** @var PdoConnection $database */
                $database->getReadPdo();
            } else {
                $database->getServerVersion();
            }
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
            return $configuration;
        }

        $options[PDO::ATTR_TIMEOUT] ??= self::CONNECTION_TIMEOUT_SECONDS;

        $configuration['options'] = $options;

        return $configuration;
    }
}
