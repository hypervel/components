<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RequestAttribute implements ExecutionScopedAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public string $parameter)
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
     * Resolve the request attribute.
     */
    public static function resolve(self $attribute, Container $container, ReflectionParameter $parameter): mixed
    {
        return $container->make('request')->attributes->get($attribute->parameter);
    }
}
