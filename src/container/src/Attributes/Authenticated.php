<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Container\Attributes\Concerns\ExtractsPropertyValue;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Authenticated implements ExecutionScopedAttribute
{
    use ExtractsPropertyValue;

    /**
     * Create a new class instance.
     *
     * Property paths use data_get() and may invoke object accessors or lazy-load
     * Eloquent relationships.
     */
    public function __construct(
        public UnitEnum|string|null $guard = null,
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
     * Resolve the currently authenticated user.
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        $value = call_user_func($container->make('auth')->userResolver(), $attribute->guard);

        return $attribute->extractPropertyValue($value, $attribute->property);
    }
}
