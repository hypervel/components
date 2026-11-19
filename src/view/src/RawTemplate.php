<?php

declare(strict_types=1);

namespace Hypervel\View;

/**
 * A raw Blade template that a component can return from its render method.
 */
class RawTemplate
{
    /**
     * Create a new raw template instance.
     *
     * @param literal-string $template
     */
    public function __construct(
        public string $template,
    ) {
    }
}
