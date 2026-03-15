<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Authenticated implements ContextualAttribute
{
    /**
     * Create a new class instance.
     */
    public function __construct(public ?string $guard = null)
    {
    }

    /**
     * Resolve the currently authenticated user.
     */
    public static function resolve(self $attribute, Container $container): ?Authenticatable
    {
        return call_user_func($container->make('auth')->userResolver(), $attribute->guard);
    }
}
