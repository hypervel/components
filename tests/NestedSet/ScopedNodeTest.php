<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Models\MenuItem;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class ScopedNodeTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        DB::enableQueryLog();

        DB::table('menu_items')
            ->insert($this->getMockMenuItems());
    }

    protected function getMockMenuItems(): array
    {
        return [
            ['id' => 1, 'menu_id' => 1, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1'],
            ['id' => 2, 'menu_id' => 1, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2'],
            ['id' => 5, 'menu_id' => 1, '_lft' => 4, '_rgt' => 5, 'parent_id' => 2, 'title' => 'menu item 3'],
            ['id' => 3, 'menu_id' => 2, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1'],
            ['id' => 4, 'menu_id' => 2, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2'],
            ['id' => 6, 'menu_id' => 2, '_lft' => 4, '_rgt' => 5, 'parent_id' => 4, 'title' => 'menu item 3'],
        ];
    }

    protected function assertTreeNotBroken(int|string $menuId): void
    {
        $this->assertFalse(MenuItem::scoped(['menu_id' => $menuId])->isBroken());
    }

    public function testNotBroken(): void
    {
        $this->assertTreeNotBroken(1);
        $this->assertTreeNotBroken(2);
    }

    public function testMovingNodeNotAffectingOtherMenu(): void
    {
        $node = MenuItem::where('menu_id', '=', 1)->first();

        $node->down();

        $node = MenuItem::where('menu_id', '=', 2)->first();

        $this->assertEquals(1, $node->getLft());
    }

    public function testScoped(): void
    {
        $node = MenuItem::scoped(['menu_id' => 2])->first();

        $this->assertEquals(3, $node->getKey());
    }

    public function testSiblings(): void
    {
        $node = MenuItem::find(1);

        $result = $node->getSiblings();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());

        $result = $node->getNextSiblings();

        $this->assertEquals(2, $result->first()->getKey());

        $node = MenuItem::find(2);

        $result = $node->getPrevSiblings();

        $this->assertEquals(1, $result->first()->getKey());
    }

    public function testDescendants(): void
    {
        $node = MenuItem::find(2);

        $result = $node->getDescendants();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(5, $result->first()->getKey());

        $node = MenuItem::scoped(['menu_id' => 1])->with('descendants')->find(2);

        $result = $node->descendants;

        $this->assertEquals(1, $result->count());
        $this->assertEquals(5, $result->first()->getKey());
    }

    public function testAncestors(): void
    {
        $node = MenuItem::find(5);

        $result = $node->getAncestors();

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());

        $node = MenuItem::scoped(['menu_id' => 1])->with('ancestors')->find(5);

        $result = $node->ancestors;

        $this->assertEquals(1, $result->count());
        $this->assertEquals(2, $result->first()->getKey());
    }

    public function testDepth(): void
    {
        $node = MenuItem::scoped(['menu_id' => 1])->withDepth()->where('id', '=', 5)->first();

        $this->assertEquals(1, $node->depth);

        $node = MenuItem::find(2);

        $result = $node->children()->withDepth()->get();

        $this->assertEquals(1, $result->first()->depth);
    }

    public function testSaveAsRoot(): void
    {
        $node = MenuItem::find(5);

        $node->saveAsRoot();

        $this->assertEquals(5, $node->getLft());
        $this->assertEquals(null, $node->parent_id);

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertion(): void
    {
        $node = MenuItem::create(['menu_id' => 1, 'parent_id' => 5]);

        $this->assertEquals(5, $node->parent_id);
        $this->assertEquals(5, $node->getLft());

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertionToParentFromOtherScope(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $node = MenuItem::create(['menu_id' => 2, 'parent_id' => 5]);
    }

    public function testDeletion(): void
    {
        $node = MenuItem::find(2)->delete();

        $node = MenuItem::find(1);

        $this->assertEquals(2, $node->getRgt());

        $this->assertOtherScopeNotAffected();
    }

    public function testMoving(): void
    {
        $node = MenuItem::find(1);
        $this->assertTrue($node->down());

        $this->assertOtherScopeNotAffected();
    }

    protected function assertOtherScopeNotAffected()
    {
        $node = MenuItem::find(3);

        $this->assertEquals(1, $node->getLft());
    }

    public function testAppendingToAnotherScopeFails(): void
    {
        $this->expectException(LogicException::class);

        $foo = MenuItem::find(1);
        $bar = MenuItem::find(3);

        $foo->appendToNode($bar)->save();
    }

    public function testInsertingBeforeAnotherScopeFails(): void
    {
        $this->expectException(LogicException::class);

        $foo = MenuItem::find(1);
        $bar = MenuItem::find(3);

        $foo->insertAfterNode($bar);
    }

    public function testEagerLoadingAncestorsWithScope(): void
    {
        $filteredNodes = MenuItem::where('title', 'menu item 3')->with(['ancestors'])->get();

        $this->assertEquals(2, $filteredNodes->find(5)->ancestors[0]->id);
        $this->assertEquals(4, $filteredNodes->find(6)->ancestors[0]->id);
    }

    public function testEagerLoadingDescendantsWithScope(): void
    {
        $filteredNodes = MenuItem::where('title', 'menu item 2')->with(['descendants'])->get();

        $this->assertEquals(5, $filteredNodes->find(2)->descendants[0]->id);
        $this->assertEquals(6, $filteredNodes->find(4)->descendants[0]->id);
    }
}
