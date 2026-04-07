<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support\Fixtures;

use Hypervel\Support\MultipleInstanceManager as BaseMultipleInstanceManager;

class MultipleInstanceManager extends BaseMultipleInstanceManager
{
    protected string $defaultInstance = 'foo';

    protected function createFooDriver(array $config): object
    {
        return new class($config) {
            public function __construct(public array $config)
            {
            }
        };
    }

    protected function createBarDriver(array $config): object
    {
        return new class($config) {
            public function __construct(public array $config)
            {
            }
        };
    }

    protected function createMysqlDatabaseConnectionDriver(array $config): object
    {
        return new class($config) {
            public function __construct(public array $config)
            {
            }
        };
    }

    /**
     * Get the default instance name.
     */
    public function getDefaultInstance(): string
    {
        return $this->defaultInstance;
    }

    /**
     * Set the default instance name.
     */
    public function setDefaultInstance(string $name): void
    {
        $this->defaultInstance = $name;
    }

    /**
     * Get the instance specific configuration.
     */
    public function getInstanceConfig(string $name): array
    {
        return match ($name) {
            'foo' => [
                'driver' => 'foo',
                'foo-option' => 'option-value',
            ],
            'bar' => [
                'driver' => 'bar',
                'bar-option' => 'option-value',
            ],
            'mysql_database-connection' => [
                'driver' => 'mysql_database-connection',
                'mysql_database-connection-option' => 'option-value',
            ],
            'custom' => [
                'driver' => 'custom',
            ],
            default => [],
        };
    }
}
