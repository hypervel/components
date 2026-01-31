<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use BadMethodCallException;
use Carbon\Carbon;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\QueryException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Models\Category;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class NodeTest extends TestCase
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

        DB::table('categories')
            ->insert($this->getMockCategories());

        // Reset Postgres sequence after inserting with explicit IDs
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('categories_id_seq', (SELECT MAX(id) FROM categories))");
        }
    }

    protected function getMockCategories(): array
    {
        return [
            ['id' => 1, 'name' => 'store', '_lft' => 1, '_rgt' => 20, 'parent_id' => null],
            ['id' => 2, 'name' => 'notebooks', '_lft' => 2, '_rgt' => 7, 'parent_id' => 1],
            ['id' => 3, 'name' => 'apple', '_lft' => 3, '_rgt' => 4, 'parent_id' => 2],
            ['id' => 4, 'name' => 'lenovo', '_lft' => 5, '_rgt' => 6, 'parent_id' => 2],
            ['id' => 5, 'name' => 'mobile', '_lft' => 8, '_rgt' => 19, 'parent_id' => 1],
            ['id' => 6, 'name' => 'nokia', '_lft' => 9, '_rgt' => 10, 'parent_id' => 5],
            ['id' => 7, 'name' => 'samsung', '_lft' => 11, '_rgt' => 14, 'parent_id' => 5],
            ['id' => 8, 'name' => 'galaxy', '_lft' => 12, '_rgt' => 13, 'parent_id' => 7],
            ['id' => 9, 'name' => 'sony', '_lft' => 15, '_rgt' => 16, 'parent_id' => 5],
            ['id' => 10, 'name' => 'lenovo', '_lft' => 17, '_rgt' => 18, 'parent_id' => 5],
            ['id' => 11, 'name' => 'store_2', '_lft' => 21, '_rgt' => 22, 'parent_id' => null],
        ];
    }

    public function tearDown(): void
    {
        parent::tearDown();

        DB::flushQueryLog();
        DB::disableQueryLog();
    }

    protected function assertTreeNotBroken(string $table = 'categories'): void
    {
        $checks = [];
        $connection = DB::connection();
        $table = $connection->getQueryGrammar()->wrapTable($table);

        // Check if lft and rgt values are ok
        $checks[] = "from {$table} where _lft >= _rgt or (_rgt - _lft) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks[] = "from {$table} c1, {$table} c2 where c1.id <> c2.id and "
            . '(c1._lft=c2._lft or c1._rgt=c2._rgt or c1._lft=c2._rgt or c1._rgt=c2._lft)';

        // Check if parent_id is set correctly
        $checks[] = "from {$table} c, {$table} p, {$table} m where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and "
            . '(c._lft not between p._lft and p._rgt or c._lft between m._lft and m._rgt and m._lft between p._lft and p._rgt)';

        foreach ($checks as $i => $check) {
            $checks[$i] = 'select 1 as error ' . $check;
        }

        $sql = 'select max(error) as errors from (' . implode(' union ', $checks) . ') _';
        $actual = $connection->selectOne($sql);

        $this->assertEquals(null, $actual->errors, "The tree structure of {$table} is broken!");

        $this->assertEquals(
            ['errors' => null],
            (array) DB::connection()->selectOne($sql),
            "The tree structure of {$table} is broken!"
        );
    }

    // for debugging purposes
    private function dumpTree($items = null): void
    {
        $items = $items ?: Category::defaultOrder()->withTrashed()->get();

        foreach ($items as $item) {
            echo PHP_EOL . ($item->trashed() ? '-' : '+') . ' ' . $item->name . ' ' . $item->getKey() . ' ' . $item->getLft() . ' ' . $item->getRgt() . ' ' . $item->getParentId();
        }
    }

    protected function assertNodeReceivesValidValues($node): void
    {
        $lft = $node->getLft();
        $rgt = $node->getRgt();
        $nodeInDb = $this->findCategory($node->name);

        $this->assertEquals(
            [$nodeInDb->getLft(), $nodeInDb->getRgt()],
            [$lft, $rgt],
            'Node is not synced with database after save.'
        );
    }

    public function findCategory(string $name, bool $withTrashed = false): ?Category
    {
        $category = new Category();
        $query = $withTrashed ? $category->withTrashed() : $category->newQuery();

        return $query->whereName($name)->first();
    }

    protected function testTreeNotBroken(): void
    {
        $this->assertTreeNotBroken();
        $this->assertFalse(Category::isBroken());
    }

    protected function nodeValues($node): array
    {
        return [$node->_lft, $node->_rgt, $node->parent_id];
    }

    public function testGetsNodeData(): void
    {
        $data = Category::getNodeData(3);

        $this->assertEquals(['_lft' => 3, '_rgt' => 4], $data);
    }

    public function testGetsPlainNodeData(): void
    {
        $data = Category::getPlainNodeData(3);

        $this->assertEquals([3, 4], $data);
    }

    public function testReceivesValidValuesWhenAppendedTo(): void
    {
        $node = new Category(['name' => 'test']);
        $root = Category::root();

        $accepted = [$root->_rgt, $root->_rgt + 1, $root->id];

        $root->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertEquals($accepted, $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isDescendantOf($root));
    }

    public function testReceivesValidValuesWhenPrependedTo(): void
    {
        $root = Category::root();
        $node = new Category(['name' => 'test']);
        $root->prependNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertEquals([$root->_lft + 1, $root->_lft + 2, $root->id], $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertTrue($node->isDescendantOf($root));
        $this->assertTrue($root->isAncestorOf($node));
        $this->assertTrue($node->isChildOf($root));
    }

    public function testReceivesValidValuesWhenInsertedAfter(): void
    {
        $target = $this->findCategory('apple');
        $node = new Category(['name' => 'test']);
        $node->afterNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals([$target->_rgt + 1, $target->_rgt + 2, $target->parent->id], $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isSiblingOf($target));
    }

    public function testReceivesValidValuesWhenInsertedBefore(): void
    {
        $target = $this->findCategory('apple');
        $node = new Category(['name' => 'test']);
        $node->beforeNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals([$target->_lft, $target->_lft + 1, $target->parent->id], $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesDown(): void
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertNodeReceivesValidValues($node);
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesUp(): void
    {
        $node = $this->findCategory('samsung');
        $target = $this->findCategory('notebooks');

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);
    }

    public function testFailsToInsertIntoChild(): void
    {
        $this->expectException(LogicException::class);

        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->afterNode($target)->save();
    }

    public function testFailsToAppendIntoItself(): void
    {
        $this->expectException(LogicException::class);

        $node = $this->findCategory('notebooks');

        $node->appendToNode($node)->save();
    }

    public function testFailsToPrependIntoItself(): void
    {
        $this->expectException(LogicException::class);

        $node = $this->findCategory('notebooks');

        $node->prependTo($node)->save();
    }

    public function testWithoutRootWorks(): void
    {
        $result = Category::withoutRoot()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself(): void
    {
        $node = $this->findCategory('apple');
        $path = $this->getAll($node->ancestors()->pluck('name'));

        $this->assertEquals(['store', 'notebooks'], $path);
    }

    public function testGetsAncestorsByStatic(): void
    {
        $path = $this->getAll(Category::ancestorsOf(3)->pluck('name'));

        $this->assertEquals(['store', 'notebooks'], $path);
    }

    public function testGetsAncestorsDirect(): void
    {
        $path = $this->getAll(Category::find(8)->getAncestors()->pluck('id'));

        $this->assertEquals([1, 5, 7], $path);
    }

    public function testDescendants(): void
    {
        $node = $this->findCategory('mobile');
        $descendants = $this->getAll($node->descendants()->pluck('name'));
        $expected = ['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'];

        $this->assertEquals($expected, $descendants);

        $descendants = $this->getAll($node->getDescendants()->pluck('name'));

        $this->assertEquals(count($descendants), $node->getDescendantCount());
        $this->assertEquals($expected, $descendants);

        $descendants = $this->getAll(Category::descendantsAndSelf(7)->pluck('name'));
        $expected = ['samsung', 'galaxy'];

        $this->assertEquals($expected, $descendants);
    }

    public function testWithDepthWorks(): void
    {
        $nodes = $this->getAll(Category::withDepth()->limit(4)->pluck('depth'));

        $this->assertEquals([0, 1, 2, 2], $nodes);
    }

    public function testWithDepthWithCustomKeyWorks(): void
    {
        $node = Category::whereIsRoot()->withDepth('level')->first();

        $this->assertTrue(isset($node['level']));
    }

    public function testWithDepthWorksAlongWithDefaultKeys(): void
    {
        $node = Category::withDepth()->first();

        $this->assertTrue(isset($node->name));
    }

    public function testParentIdAttributeAccessorAppendsNode(): void
    {
        $node = new Category(['name' => 'lg', 'parent_id' => 5]);
        $node->save();

        $this->assertEquals(5, $node->parent_id);
        $this->assertEquals(5, $node->getParentId());

        $node->parent_id = null;
        $node->save();

        $node->refreshNode();

        $this->assertNull($node->parent_id);
        $this->assertTrue($node->isRoot());
    }

    public function testFailsToSaveNodeUntilNotInserted(): void
    {
        $this->expectException(QueryException::class);

        $node = new Category();
        $node->save();
    }

    public function testNodeIsDeletedWithDescendants(): void
    {
        $node = $this->findCategory('mobile');
        $node->forceDelete();

        $this->assertTreeNotBroken();

        $nodes = Category::whereIn('id', [5, 6, 7, 8, 9])->count();
        $this->assertEquals(0, $nodes);

        $root = Category::root();
        $this->assertEquals(8, $root->getRgt());
    }

    public function testNodeIsSoftDeleted(): void
    {
        Carbon::setTestNow('2025-07-03 12:00:00');

        $root = Category::root();

        $samsung = $this->findCategory('samsung');
        $samsung->delete();

        $this->assertTreeNotBroken();
        $this->assertNull($this->findCategory('galaxy'));

        Carbon::setTestNow('2025-07-03 12:00:01');

        $node = $this->findCategory('mobile');
        $node->delete();

        $nodes = Category::whereIn('id', [5, 6, 7, 8, 9])->count();
        $this->assertEquals(0, $nodes);

        $originalRgt = $root->getRgt();
        $root->refreshNode();

        $this->assertEquals($originalRgt, $root->getRgt());

        $node = $this->findCategory('mobile', true);
        $node->restore();

        $this->assertNull($this->findCategory('samsung'));
        $this->assertNotNull($this->findCategory('nokia'));
    }

    public function testSoftDeletedNodeIsDeletedWhenParentIsDeleted(): void
    {
        $this->findCategory('samsung')->delete();

        $this->findCategory('mobile')->forceDelete();

        $this->assertTreeNotBroken();

        $this->assertNull($this->findCategory('samsung', true));
        $this->assertNull($this->findCategory('sony'));
    }

    public function testFailsToSaveNodeUntilParentIsSaved(): void
    {
        $this->expectException(BadMethodCallException::class);

        $node = new Category(['name' => 'Node']);
        $parent = new Category(['name' => 'Parent']);

        $node->appendTo($parent)->save();
    }

    public function testSiblings(): void
    {
        $node = $this->findCategory('samsung');
        $siblings = $this->getAll($node->siblings()->pluck('id'));
        $next = $this->getAll($node->nextSiblings()->pluck('id'));
        $prev = $this->getAll($node->prevSiblings()->pluck('id'));

        $this->assertEquals([6, 9, 10], $siblings);
        $this->assertEquals([9, 10], $next);
        $this->assertEquals([6], $prev);

        $siblings = $this->getAll($node->getSiblings()->pluck('id'));
        $next = $this->getAll($node->getNextSiblings()->pluck('id'));
        $prev = $this->getAll($node->getPrevSiblings()->pluck('id'));

        $this->assertEquals([6, 9, 10], $siblings);
        $this->assertEquals([9, 10], $next);
        $this->assertEquals([6], $prev);

        $next = $node->getNextSibling();
        $prev = $node->getPrevSibling();

        $this->assertEquals(9, $next->id);
        $this->assertEquals(6, $prev->id);
    }

    public function testFetchesReversed(): void
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->value('id');

        $this->assertEquals(7, $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder(): void
    {
        $tree = Category::whereBetween('_lft', [8, 17])->defaultOrder()->get()->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
    }

    public function testToTreeBuildsWithCustomOrder(): void
    {
        $tree = Category::whereBetween('_lft', [8, 17])
            ->orderBy('name')
            ->get()
            ->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
        $this->assertEquals($root, $root->children->first()->parent);
    }

    public function testToTreeWithSpecifiedRoot(): void
    {
        $node = $this->findCategory('mobile');
        $nodes = Category::whereBetween('_lft', [8, 17])->get();

        $tree1 = Collection::make($nodes)->toTree(5);
        $tree2 = Collection::make($nodes)->toTree($node);

        $this->assertEquals(4, $tree1->count());
        $this->assertEquals(4, $tree2->count());
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes(): void
    {
        $tree = Category::withoutRoot()->get()->toTree();

        $this->assertEquals(2, count($tree));
    }

    public function testToTreeBuildsWithRootItemIdProvided(): void
    {
        $tree = Category::whereBetween('_lft', [8, 17])->get()->toTree(5);

        $this->assertEquals(4, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function testRetrievesNextNode(): void
    {
        $node = $this->findCategory('apple');
        $next = $node->nextNodes()->first();

        $this->assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode(): void
    {
        $node = $this->findCategory('apple');
        $next = $node->getPrevNode();

        $this->assertEquals('notebooks', $next->name);
    }

    public function testMultipleAppendageWorks(): void
    {
        $parent = $this->findCategory('mobile');

        $child = new Category(['name' => 'test']);

        $parent->appendNode($child);

        $child->appendNode(new Category(['name' => 'sub']));

        $parent->appendNode(new Category(['name' => 'test2']));

        $this->assertTreeNotBroken();
    }

    public function testDefaultCategoryIsSavedAsRoot(): void
    {
        $node = new Category(['name' => 'test']);
        $node->save();

        $this->assertEquals(23, $node->_lft);
        $this->assertTreeNotBroken();

        $this->assertTrue($node->isRoot());
    }

    public function testExistingCategorySavedAsRoot(): void
    {
        $node = $this->findCategory('apple');
        $node->saveAsRoot();

        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());
    }

    public function testNodeMovesDownSeveralPositions(): void
    {
        $node = $this->findCategory('nokia');

        $this->assertTrue($node->down(2));

        $this->assertEquals($node->_lft, 15);
    }

    public function testNodeMovesUpSeveralPositions(): void
    {
        $node = $this->findCategory('sony');

        $this->assertTrue($node->up(2));

        $this->assertEquals($node->_lft, 9);
    }

    public function testCountsTreeErrors(): void
    {
        $errors = Category::countErrors();

        $this->assertEquals([
            'oddness' => 0,
            'duplicates' => 0,
            'wrong_parent' => 0,
            'missing_parent' => 0,
        ], $errors);

        Category::where('id', '=', 5)->update(['_lft' => 14]);
        Category::where('id', '=', 8)->update(['parent_id' => 2]);
        Category::where('id', '=', 11)->update(['_lft' => 20]);
        Category::where('id', '=', 4)->update(['parent_id' => 24]);

        $errors = Category::countErrors();

        $this->assertEquals(1, $errors['oddness']);
        $this->assertEquals(2, $errors['duplicates']);
        $this->assertEquals(1, $errors['missing_parent']);
    }

    public function testCreatesNode(): void
    {
        $node = Category::create(['name' => 'test']);

        $this->assertEquals(23, $node->getLft());
    }

    public function testCreatesViaRelationship(): void
    {
        $node = $this->findCategory('apple');

        $child = $node->children()->create(['name' => 'test']);

        $this->assertTreeNotBroken();
    }

    public function testCreatesTree(): void
    {
        $node = Category::create(
            [
                'name' => 'test',
                'children' => [
                    ['name' => 'test2'],
                    ['name' => 'test3'],
                ],
            ]
        );

        $this->assertTreeNotBroken();

        $this->assertTrue(isset($node->children));

        $node = $this->findCategory('test');

        $this->assertCount(2, $node->children);
        $this->assertEquals('test2', $node->children[0]->name);
    }

    public function testDescendantsOfNonExistingNode(): void
    {
        $node = new Category();

        $this->assertTrue($node->getDescendants()->isEmpty());
    }

    public function testWhereDescendantsOf(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Category::whereDescendantOf(124)->get();
    }

    public function testAncestorsByNode(): void
    {
        $category = $this->findCategory('apple');
        $ancestors = $this->getAll(Category::whereAncestorOf($category)->pluck('id'));

        $this->assertEquals([1, 2], $ancestors);
    }

    public function testDescendantsByNode(): void
    {
        $category = $this->findCategory('notebooks');
        $res = $this->getAll(Category::whereDescendantOf($category)->pluck('id'));

        $this->assertEquals([3, 4], $res);
    }

    public function testMultipleDeletionsDoNotBrakeTree(): void
    {
        $category = $this->findCategory('mobile');

        foreach ($category->children()->take(2)->get() as $child) {
            $child->forceDelete();
        }

        $this->assertTreeNotBroken();
    }

    public function testTreeIsFixed(): void
    {
        Category::where('id', '=', 5)->update(['_lft' => 14]);
        Category::where('id', '=', 8)->update(['parent_id' => 2]);
        Category::where('id', '=', 11)->update(['_lft' => 20]);
        Category::where('id', '=', 2)->update(['parent_id' => 24]);

        $fixed = Category::fixTree();

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = Category::find(8);

        $this->assertEquals(2, $node->getParentId());

        $node = Category::find(2);

        $this->assertEquals(null, $node->getParentId());
    }

    public function testSubtreeIsFixed(): void
    {
        Category::where('id', '=', 8)->update(['_lft' => 11]);

        $fixed = Category::fixSubtree(Category::find(5));
        $this->assertEquals($fixed, 1);
        $this->assertTreeNotBroken();
        $this->assertEquals(Category::find(8)->getLft(), 12);
    }

    public function testParentIdDirtiness(): void
    {
        $node = $this->findCategory('apple');
        $node->parent_id = 5;

        $this->assertTrue($node->isDirty('parent_id'));

        $node = $this->findCategory('apple');
        $node->parent_id = null;

        $this->assertTrue($node->isDirty('parent_id'));
    }

    public function testIsDirtyMovement(): void
    {
        $node = $this->findCategory('apple');
        $otherNode = $this->findCategory('samsung');

        $this->assertFalse($node->isDirty());

        $node->afterNode($otherNode);

        $this->assertTrue($node->isDirty());

        $node = $this->findCategory('apple');
        $otherNode = $this->findCategory('samsung');

        $this->assertFalse($node->isDirty());

        $node->appendToNode($otherNode);

        $this->assertTrue($node->isDirty());
    }

    public function testRootNodesMoving(): void
    {
        $node = $this->findCategory('store');
        $node->down();

        $this->assertEquals(3, $node->getLft());
    }

    public function testDescendantsRelation(): void
    {
        $node = $this->findCategory('notebooks');
        $result = $node->descendants;

        $this->assertEquals(2, $result->count());
        $this->assertEquals('apple', $result->first()->name);
    }

    public function testDescendantsEagerlyLoaded(): void
    {
        $nodes = Category::whereIn('id', [2, 5])->get();

        $nodes->load('descendants');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('descendants'));
    }

    public function testDescendantsRelationQuery(): void
    {
        $nodes = Category::has('descendants')->whereIn('id', [2, 3])->get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals(2, $nodes->first()->getKey());

        $nodes = Category::has('descendants', '>', 2)->get();

        $this->assertEquals(2, $nodes->count());
        $this->assertEquals(1, $nodes[0]->getKey());
        $this->assertEquals(5, $nodes[1]->getKey());
    }

    public function testParentRelationQuery(): void
    {
        $nodes = Category::has('parent')->whereIn('id', [1, 2]);

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals(2, $nodes->first()->getKey());
    }

    public function testRebuildTree(): void
    {
        $fixed = Category::rebuildTree([
            [
                'id' => 1,
                'children' => [
                    ['id' => 10],
                    ['id' => 3, 'name' => 'apple v2', 'children' => [['name' => 'new node']]],
                    ['id' => 2],
                ],
            ],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = Category::find(3);

        $this->assertEquals(1, $node->getParentId());
        $this->assertEquals('apple v2', $node->name);
        $this->assertEquals(4, $node->getLft());

        $node = $this->findCategory('new node');

        $this->assertNotNull($node);
        $this->assertEquals(3, $node->getParentId());
    }

    public function testRebuildSubtree(): void
    {
        $fixed = Category::rebuildSubtree(Category::find(7), [
            ['name' => 'new node'],
            ['id' => '8'],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = $this->findCategory('new node');

        $this->assertNotNull($node);
        $this->assertEquals($node->getLft(), 12);
    }

    public function testRebuildTreeWithDeletion(): void
    {
        Category::rebuildTree([['name' => 'all deleted']], true);

        $this->assertTreeNotBroken();

        $nodes = Category::get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals('all deleted', $nodes->first()->name);

        $nodes = Category::withTrashed()->get();

        $this->assertTrue($nodes->count() > 1);
    }

    public function testRebuildFailsWithInvalidPK(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Category::rebuildTree([['id' => 24]]);
    }

    public function testFlatTree(): void
    {
        $node = $this->findCategory('mobile');
        $tree = $node->descendants()->orderBy('name')->get()->toFlatTree();

        $this->assertCount(5, $tree);
        $this->assertEquals('samsung', $tree[2]->name);
        $this->assertEquals('galaxy', $tree[3]->name);
    }

    public function testWhereIsLeaf(): void
    {
        $categories = Category::leaves();

        $this->assertEquals(7, $categories->count());
        $this->assertEquals('apple', $categories->first()->name);
        $this->assertTrue($categories->first()->isLeaf());

        $category = Category::whereIsRoot()->first();

        $this->assertFalse($category->isLeaf());
    }

    public function testEagerLoadAncestors(): void
    {
        $queryLogCount = count(DB::getQueryLog());
        $categories = Category::with('ancestors')->orderBy('name')->get();

        $this->assertEquals($queryLogCount + 2, count(DB::getQueryLog()));

        $expectedShape = [
            'apple (3)}' => 'store (1) > notebooks (2)',
            'galaxy (8)}' => 'store (1) > mobile (5) > samsung (7)',
            'lenovo (4)}' => 'store (1) > notebooks (2)',
            'lenovo (10)}' => 'store (1) > mobile (5)',
            'mobile (5)}' => 'store (1)',
            'nokia (6)}' => 'store (1) > mobile (5)',
            'notebooks (2)}' => 'store (1)',
            'samsung (7)}' => 'store (1) > mobile (5)',
            'sony (9)}' => 'store (1) > mobile (5)',
            'store (1)}' => '',
            'store_2 (11)}' => '',
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function ($cat) {
                    return "{$cat->name} ({$cat->id})";
                })->toArray())
                : '';
        }

        $this->assertEquals($expectedShape, $output);
    }

    public function testLazyLoadAncestors(): void
    {
        $queryLogCount = count(DB::getQueryLog());
        $categories = Category::orderBy('name')->get();

        $this->assertEquals($queryLogCount + 1, count(DB::getQueryLog()));

        $expectedShape = [
            'apple (3)}' => 'store (1) > notebooks (2)',
            'galaxy (8)}' => 'store (1) > mobile (5) > samsung (7)',
            'lenovo (4)}' => 'store (1) > notebooks (2)',
            'lenovo (10)}' => 'store (1) > mobile (5)',
            'mobile (5)}' => 'store (1)',
            'nokia (6)}' => 'store (1) > mobile (5)',
            'notebooks (2)}' => 'store (1)',
            'samsung (7)}' => 'store (1) > mobile (5)',
            'sony (9)}' => 'store (1) > mobile (5)',
            'store (1)}' => '',
            'store_2 (11)}' => '',
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function ($cat) {
                    return "{$cat->name} ({$cat->id})";
                })->toArray())
                : '';
        }

        // assert that there is number of original query + 1 + number of rows to fulfill the relation
        $this->assertEquals($queryLogCount + 12, count(DB::getQueryLog()));

        $this->assertEquals($expectedShape, $output);
    }

    public function testWhereHasCountQueryForAncestors(): void
    {
        $categories = $this->getAll(Category::has('ancestors', '>', 2)->pluck('name'));

        $this->assertEquals(['galaxy'], $categories);

        $categories = $this->getAll(Category::whereHas('ancestors', function ($query) {
            $query->where('id', 5);
        })->pluck('name'));

        $this->assertEquals(['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'], $categories);
    }

    public function testReplication(): void
    {
        $category = $this->findCategory('nokia');
        $category = $category->replicate();
        $category->save();
        $category->refreshNode();

        $this->assertNull($category->getParentId());

        $category = $this->findCategory('nokia');
        $category = $category->replicate();
        $category->parent_id = 1;
        $category->save();

        $category->refreshNode();

        $this->assertEquals(1, $category->getParentId());
    }

    protected function getAll(array|BaseCollection $items): array
    {
        return is_array($items) ? $items : $items->all();
    }
}
