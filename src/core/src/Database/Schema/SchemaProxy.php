<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Schema\Builder;

/**
 * @mixin Builder
 */
class SchemaProxy
{
    public function __call(string $name, array $arguments)
    {
        return $this->connection()
            ->{$name}(...$arguments);
    }

    /**
     * Get schema builder with specific connection.
     */
    public function connection(?string $name = null): Builder
    {
        $resolver = ApplicationContext::getContainer()
            ->get(ConnectionResolverInterface::class);

        $connection = $resolver->connection(
            $name ?: $resolver->getDefaultConnection()
        );

        return $connection->getSchemaBuilder();
    }
}
