<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Cache implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $store = null)
    {
    }

    /**
     * Resolve the cache store.
     *
     * @param  self  $attribute
     * @param  \Hypervel\Contracts\Container\Container  $container
     * @return \Hypervel\Contracts\Cache\Repository
     */
    public static function resolve(self $attribute, Container $container)
    {
        return $container->make('cache')->store($attribute->store);
    }
}
