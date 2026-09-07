<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use Hypervel\Database\ConnectionInterface;
use UnitEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Database implements ExecutionScopedAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public UnitEnum|string|null $connection = null)
    {
    }

    /**
     * Determine whether the resolved value belongs to the current execution.
     */
    public function isExecutionScoped(): bool
    {
        return true;
    }

    /**
     * Resolve the database connection.
     */
    public static function resolve(self $attribute, Container $container): ConnectionInterface
    {
        return $container->make('db')->connection($attribute->connection);
    }
}
