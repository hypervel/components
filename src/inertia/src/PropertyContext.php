<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Http\Request;

class PropertyContext
{
    /**
     * Create a new property context instance. The property context provides
     * information about the current property being resolved to objects
     * implementing ProvidesInertiaProperty.
     *
     * @param array<string, mixed> $props
     */
    public function __construct(
        public readonly string $key,
        public readonly array $props,
        public readonly Request $request,
    ) {
    }
}
