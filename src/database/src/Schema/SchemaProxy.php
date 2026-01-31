<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Context\ApplicationContext;
use Hypervel\Database\DatabaseManager;

/**
 * @mixin Builder
 */
class SchemaProxy
{
    public function __call(string $name, array $arguments): mixed
    {
        return $this->connection()
            ->{$name}(...$arguments);
    }

    /**
     * Get schema builder with specific connection.
     *
     * Routes through DatabaseManager to respect usingConnection() overrides.
     */
    public function connection(?string $name = null): Builder
    {
        return ApplicationContext::getContainer()
            ->get(DatabaseManager::class)
            ->connection($name)
            ->getSchemaBuilder();
    }
}
