<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Config implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public string $key, public mixed $default = null)
    {
    }

    /**
     * Resolve the configuration value.
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        // @phpstan-ignore hypervel.untypedConfigRead (The injected configuration value can have any type.)
        return $container->make('config')->get($attribute->key, $attribute->default);
    }
}
