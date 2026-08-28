<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RouteParameter implements ExecutionScopedAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $parameter = null)
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
     * Resolve the route parameter.
     */
    public static function resolve(self $attribute, Container $container, ReflectionParameter $parameter): mixed
    {
        return $container->make('request')->route($attribute->parameter ?? $parameter->getName());
    }
}
