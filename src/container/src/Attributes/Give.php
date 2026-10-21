<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Container\Attributes\Concerns\ExtractsPropertyValue;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Give implements ContextualAttribute
{
    use ExtractsPropertyValue;

    /**
     * Provide a concrete class implementation for dependency injection.
     *
     * Property paths use data_get() and may invoke object accessors or lazy-load
     * Eloquent relationships.
     */
    public function __construct(
        public string $class,
        public array $params = [],
        public ?string $property = null,
    ) {
    }

    /**
     * Resolve the dependency.
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        return $attribute->extractPropertyValue(
            $container->make($attribute->class, $attribute->params),
            $attribute->property,
        );
    }
}
