<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Inertia\ProvidesInertiaProperties;
use Hypervel\Inertia\RenderContext;

class ExampleInertiaPropsProvider implements ProvidesInertiaProperties
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        protected array $properties
    ) {
    }

    /**
     * @return iterable<string, mixed>
     */
    public function toInertiaProperties(RenderContext $context): iterable
    {
        return $this->properties;
    }
}
