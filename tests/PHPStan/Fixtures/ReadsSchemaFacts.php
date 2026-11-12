<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures;

use Hypervel\PHPStan\Properties\ConnectionConfig;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\Schema\DataTypeToPhpTypeConverter;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\PHPStan\Properties\SchemaTable;
use PHPStan\Type\VerbosityLevel;

use function is_string;

trait ReadsSchemaFacts
{
    /**
     * Get the facts of the owner a connection's schema belongs to, or null when there are none.
     *
     * @param array<string, SchemaConnection> $connections
     */
    protected function findSchema(array $connections, ?string $connection = null): ?SchemaConnection
    {
        return $connections[$this->connectionConfig($connection)->owner] ?? null;
    }

    /**
     * Get the facts of a table as a model on the connection looks it up, or null when it is not known.
     *
     * @param array<string, SchemaConnection> $connections
     */
    protected function findTable(array $connections, string $table, ?string $connection = null): ?SchemaTable
    {
        $config = $this->connectionConfig($connection);
        $schema = $connections[$config->owner] ?? null;
        [$tableSchema, $name] = $config->physicalTable($table);
        $key = $schema?->findTableKey($tableSchema, $name, $config->defaultSchema, $config->searchPath);

        return $schema !== null && is_string($key) ? $schema->tables[$key] : null;
    }

    /**
     * Get a table's columns as their read types (null when unknown) and nullability, or null when the table is not known.
     *
     * @param array<string, SchemaConnection> $connections
     *
     * @return null|array<string, array{?string, bool}>
     */
    protected function describeColumns(array $connections, string $table, ?string $connection = null): ?array
    {
        $config = $this->connectionConfig($connection);
        $schema = $this->findSchema($connections, $connection);
        $tableFacts = $this->findTable($connections, $table, $connection);

        if ($schema === null || $tableFacts === null) {
            return null;
        }

        $converter = self::getContainer()->getByType(DataTypeToPhpTypeConverter::class);
        $columns = [];

        foreach ($tableFacts->columns as $column) {
            $columns[$column->name] = [
                $column->type === null
                    ? null
                    : $converter->convert($schema->driver, $column->type, $column->options, $schema, $tableFacts->searchPath ?? $config->searchPath)['readable']->describe(VerbosityLevel::precise()),
                $column->nullable,
            ];
        }

        return $columns;
    }

    /**
     * Get the facts of a connection, or of the default connection when no name is given, failing when they cannot be resolved.
     */
    private function connectionConfig(?string $connection): ConnectionConfig
    {
        $resolver = self::getContainer()->getByType(ConnectionConfigResolver::class);
        $config = $resolver->connection($connection ?? (string) $resolver->getDefaultConnectionName());

        $this->assertNotNull($config);

        return $config;
    }
}
