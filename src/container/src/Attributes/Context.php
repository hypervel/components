<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use Hypervel\Log\Context\Repository;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Context implements ExecutionScopedAttribute
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $key, public mixed $default = null, public bool $hidden = false)
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
     * Resolve the context value.
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        $repository = Repository::getInstance();

        return match ($attribute->hidden) {
            true => $repository->getHidden($attribute->key, $attribute->default),
            false => $repository->get($attribute->key, $attribute->default),
        };
    }
}
