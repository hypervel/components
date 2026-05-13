<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\HasNode;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Tests\TestCase;
use stdClass;

class NestedSetTest extends TestCase
{
    public function testIsNodeReturnsTrueForModelUsingHasNode(): void
    {
        $this->assertTrue(NestedSet::isNode(new NestedSetTestNodeModel));
    }

    public function testIsNodeReturnsFalseForPlainEloquentModel(): void
    {
        $this->assertFalse(NestedSet::isNode(new NestedSetTestPlainModel));
    }

    public function testIsNodeReturnsFalseForNonObject(): void
    {
        $this->assertFalse(NestedSet::isNode('not an object'));
        $this->assertFalse(NestedSet::isNode(42));
        $this->assertFalse(NestedSet::isNode(null));
        $this->assertFalse(NestedSet::isNode([]));
    }

    public function testIsNodeReturnsFalseForArbitraryObject(): void
    {
        $this->assertFalse(NestedSet::isNode(new stdClass));
    }
}

class NestedSetTestNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_nodes';
}

class NestedSetTestPlainModel extends Model
{
    protected ?string $table = 'nested_set_test_plain';
}
