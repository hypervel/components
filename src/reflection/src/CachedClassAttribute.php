<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ReflectionClass;

readonly class CachedClassAttribute
{
    /**
     * Create a new cached class attribute metadata instance.
     */
    public function __construct(
        public object $instance,
        public ReflectionClass $declaringClass,
    ) {
    }
}
