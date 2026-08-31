<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Authenticated implements ExecutionScopedAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public UnitEnum|string|null $guard = null)
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
     * Resolve the currently authenticated user.
     */
    public static function resolve(self $attribute, Container $container): ?Authenticatable
    {
        return call_user_func($container->make('auth')->userResolver(), $attribute->guard);
    }
}
