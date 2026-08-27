<?php

declare(strict_types=1);

namespace Hypervel\Database\Connectors;

use Exception;
use Hypervel\Database\DetectsLostConnections;
use InvalidArgumentException;
use PDO;
use SensitiveParameter;
use Throwable;

class Connector
{
    use DetectsLostConnections;

    /**
     * The default PDO connection options.
     */
    protected array $options = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * Create a new PDO connection.
     *
     * @throws Exception
     */
    public function createConnection(string $dsn, array $config, array $options): PDO
    {
        [$username, $password] = [
            $config['username'] ?? null, $config['password'] ?? null,
        ];

        try {
            return $this->createPdoConnection(
                $dsn,
                $username,
                $password,
                $options
            );
        } catch (Exception $e) {
            return $this->tryAgainIfCausedByLostConnection(
                $e,
                $dsn,
                $username,
                $password,
                $options
            );
        }
    }

    /**
     * Create a new PDO connection instance.
     */
    protected function createPdoConnection(string $dsn, ?string $username, #[SensitiveParameter] ?string $password, array $options): PDO
    {
        return PDO::connect($dsn, $username, $password, $options);
    }

    /**
     * Handle an exception that occurred during connect execution.
     *
     * @throws Throwable
     */
    protected function tryAgainIfCausedByLostConnection(Throwable $e, string $dsn, ?string $username, #[SensitiveParameter] ?string $password, array $options): PDO
    {
        if ($this->causedByLostConnection($e)) {
            return $this->createPdoConnection($dsn, $username, $password, $options);
        }

        throw $e;
    }

    /**
     * Get the PDO options based on the configuration.
     */
    public function getOptions(array $config): array
    {
        $options = $config['options'] ?? [];

        return array_diff_key($this->options, $options) + $options;
    }

    /**
     * Get the default PDO connection options.
     */
    public function getDefaultOptions(): array
    {
        return $this->options;
    }

    /**
     * Set the default PDO connection options.
     */
    public function setDefaultOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Get the configured lock timeout in seconds.
     */
    protected function getLockTimeout(array $config): ?int
    {
        if (! isset($config['lock_timeout'])) {
            return null;
        }

        if (! is_int($config['lock_timeout']) || $config['lock_timeout'] < 1) {
            throw new InvalidArgumentException('Database connection [lock_timeout] must be a positive integer.');
        }

        return $config['lock_timeout'];
    }
}
