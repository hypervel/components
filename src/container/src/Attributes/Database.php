<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Database implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public UnitEnum|string|null $connection = null)
    {
    }

    /**
     * Resolve the database connection.
     *
     * @param  self  $attribute
     * @param  \Hypervel\Contracts\Container\Container  $container
     * @return \Hypervel\Database\Connection
     */
    public static function resolve(self $attribute, Container $container)
    {
        return $container->make('db')->connection($attribute->connection);
    }
}
