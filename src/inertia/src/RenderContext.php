<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Http\Request;

class RenderContext
{
    /**
     * Create a new render context instance. The render context provides
     * information about the current Inertia render operation to objects
     * implementing ProvidesInertiaProperties.
     */
    public function __construct(
        public readonly string $component,
        public readonly Request $request,
    ) {
    }
}
