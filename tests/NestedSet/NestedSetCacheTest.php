<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Tests\NestedSet\Fixtures\Models\Category;
use Hypervel\Tests\NestedSet\Fixtures\Models\CategoryUuid;
use Hypervel\Tests\NestedSet\Fixtures\Models\MenuItem;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use stdClass;

class NestedSetCacheTest extends TestCase
{
    public function testUsesSoftDeleteCheckIsCachedPerModelClass(): void
    {
        $this->assertTrue(Category::usesSoftDelete());
        $this->assertFalse(MenuItem::usesSoftDelete());

        $this->assertSame([
            Category::class => true,
            MenuItem::class => false,
        ], (new ReflectionProperty(Model::class, 'isSoftDeletable'))->getValue());
    }

    public function testNodeTraitCheckIsCachedPerClass(): void
    {
        $this->assertTrue(NestedSet::isNode(new Category));
        $this->assertTrue(NestedSet::isNode(new CategoryUuid));
        $this->assertFalse(NestedSet::isNode(new stdClass));
        $this->assertFalse(NestedSet::isNode(null));

        $cache = new ReflectionProperty(NestedSet::class, 'nodeClasses');

        $this->assertSame([
            Category::class => true,
            CategoryUuid::class => true,
            stdClass::class => false,
        ], $cache->getValue());

        NestedSet::flushState();

        $this->assertSame([], $cache->getValue());
    }
}
