<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Cache implements ExecutionScopedAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public UnitEnum|string|null $store = null,
        public bool $memo = false,
    ) {
    }

    /**
     * Determine whether the resolved value belongs to the current execution.
     */
    public function isExecutionScoped(): bool
    {
        return $this->memo;
    }

    /**
     * Resolve the cache store.
     */
    public static function resolve(self $attribute, Container $container): Repository
    {
        return $attribute->memo
            ? $container->make('cache')->memo($attribute->store)
            : $container->make('cache')->store($attribute->store);
    }
}
