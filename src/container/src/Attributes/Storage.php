<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Storage implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $disk = null)
    {
    }

    /**
     * Resolve the storage disk.
     *
     * @param  self  $attribute
     * @param  \Hypervel\Contracts\Container\Container  $container
     * @return \Hypervel\Contracts\Filesystem\Filesystem
     */
    public static function resolve(self $attribute, Container $container)
    {
        return $container->make('filesystem')->disk($attribute->disk);
    }
}
