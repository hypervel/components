<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RouteParameter implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $parameter = null)
    {
    }

    /**
     * Resolve the route parameter.
     */
    public static function resolve(self $attribute, Container $container, ReflectionParameter $parameter): mixed
    {
        return $container->make('request')->route($attribute->parameter ?? $parameter->getName());
    }
}
