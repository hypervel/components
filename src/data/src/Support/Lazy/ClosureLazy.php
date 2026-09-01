<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Lazy;

use Closure;

class ClosureLazy extends ConditionalLazy
{
    /**
     * Create a lazy closure value.
     */
    public function __construct(
        Closure $closure,
    ) {
        parent::__construct(fn () => true, $closure);
    }

    /**
     * Resolve the closure without invoking it.
     */
    public function resolve(): Closure
    {
        return $this->value;
    }
}
