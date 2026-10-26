<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Fixtures;

use Hypervel\Head\Tags\TagBuilder;

/**
 * @phpstan-consistent-constructor
 */
class ConflictingRouteAttribute extends TagBuilder
{
    /**
     * Get the custom builder key.
     */
    public static function key(): string
    {
        return 'conflictingRouteAttribute';
    }

    /**
     * Deliberately claim a built-in route attribute.
     *
     * @return array<int, string>
     */
    public static function routeAttributeKeys(): array
    {
        return ['title'];
    }

    /**
     * Keep this fixture as the overlay.
     */
    public function overlayOn(?TagBuilder $base): static
    {
        return $this;
    }

    /**
     * Determine whether the fixture has no data.
     */
    public function isEmpty(): bool
    {
        return true;
    }
}
