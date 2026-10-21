<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Tests\NestedSet\Fixtures\Models\MenuItemUuid;

class ScopedNodeUuidTest extends ScopedNodeTestBase
{
    protected string $menuItem = MenuItemUuid::class;

    /**
     * Get the fixture migration path.
     */
    protected function getMigrationPath(): string
    {
        return __DIR__ . '/Fixtures/migrations/uuid';
    }

    /**
     * Get the key of a seeded fixture menu item.
     *
     * Keys sort in the same order as their numbers, so ordered assertions match the integer variant.
     */
    protected function key(int $number): string
    {
        return sprintf('018f3a2b-0000-7000-8000-%012d', $number);
    }
}
