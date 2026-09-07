<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Container\Attributes\Concerns\ExtractsPropertyValue;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RouteParameter implements ExecutionScopedAttribute
{
    use ExtractsPropertyValue;

    /**
     * Create a new class instance.
     *
     * Property paths use data_get() and may invoke object accessors or lazy-load
     * Eloquent relationships.
     */
    public function __construct(
        public ?string $parameter = null,
        public ?string $property = null,
    ) {
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
        $value = $container->make('request')->route($attribute->parameter ?? $parameter->getName());

        return $attribute->extractPropertyValue($value, $attribute->property);
    }
}
