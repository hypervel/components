<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes;

use Attribute;
use Closure;
use Hypervel\Contracts\Container\Container;

/**
 * Define a binding selected by a boot-stable condition.
 *
 * A matching condition becomes a normal worker-lifetime container binding.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class BindWhen
{
    /**
     * The concrete class to bind to.
     *
     * @var class-string
     */
    public string $concrete;

    /**
     * The condition that determines if the binding should apply.
     *
     * @var Closure(Container): bool
     */
    public Closure $condition;

    /**
     * Create a new attribute instance.
     *
     * @param class-string $concrete
     * @param Closure(Container): bool $condition
     */
    public function __construct(string $concrete, Closure $condition)
    {
        $this->concrete = $concrete;
        $this->condition = $condition;
    }
}
