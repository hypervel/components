<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Contracts\Attributes;

/**
 * Interface for meta-attributes that resolve to actual attribute classes.
 */
interface Resolvable
{
    /**
     * Resolve the actual attribute class.
     */
    public function resolve(): mixed;
}
