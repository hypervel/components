<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Hypervel\Testbench\Contracts\Attributes\Resolvable;
use Hypervel\Testbench\Contracts\Attributes\TestingFeature;

/**
 * Meta-attribute that resolves to actual attribute classes based on group.
 *
 * Provides a shorthand for common attribute types.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Define implements Resolvable
{
    public function __construct(
        public readonly string $group,
        public readonly string $method
    ) {
    }

    /**
     * Resolve the actual attribute class.
     */
    public function resolve(): ?TestingFeature
    {
        return match (strtolower($this->group)) {
            'env' => new DefineEnvironment($this->method),
            'db' => new DefineDatabase($this->method),
            'route' => new DefineRoute($this->method),
            default => null,
        };
    }
}
