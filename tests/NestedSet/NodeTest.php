<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\HasNode;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Models\Category;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class NodeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

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
            ['id' => 1, 'name' => 'store', '_lft' => 1, '_rgt' => 20, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'notebooks', '_lft' => 2, '_rgt' => 7, 'parent_id' => 1, 'depth' => 1],
            ['id' => 3, 'name' => 'apple', '_lft' => 3, '_rgt' => 4, 'parent_id' => 2, 'depth' => 2],
            ['id' => 4, 'name' => 'lenovo', '_lft' => 5, '_rgt' => 6, 'parent_id' => 2, 'depth' => 2],
            ['id' => 5, 'name' => 'mobile', '_lft' => 8, '_rgt' => 19, 'parent_id' => 1, 'depth' => 1],
            ['id' => 6, 'name' => 'nokia', '_lft' => 9, '_rgt' => 10, 'parent_id' => 5, 'depth' => 2],
            ['id' => 7, 'name' => 'samsung', '_lft' => 11, '_rgt' => 14, 'parent_id' => 5, 'depth' => 2],
            ['id' => 8, 'name' => 'galaxy', '_lft' => 12, '_rgt' => 13, 'parent_id' => 7, 'depth' => 3],
            ['id' => 9, 'name' => 'sony', '_lft' => 15, '_rgt' => 16, 'parent_id' => 5, 'depth' => 2],
            ['id' => 10, 'name' => 'lenovo', '_lft' => 17, '_rgt' => 18, 'parent_id' => 5, 'depth' => 2],
            ['id' => 11, 'name' => 'store_2', '_lft' => 21, '_rgt' => 22, 'parent_id' => null, 'depth' => 0],
        ];
    }

    public function tearDown(): void
    {
        DB::flushQueryLog();
        DB::disableQueryLog();

        parent::tearDown();
    }

    protected function assertTreeNotBroken(): void
    {
        $this->assertSame([
            'invalid_intervals' => 0,
            'duplicate_endpoints' => 0,
            'missing_endpoints' => 0,
            'crossing_intervals' => 0,
            'missing_parent' => 0,
            'wrong_parent' => 0,
            'wrong_depth' => 0,
        ], Category::countErrors());
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
        $category = new Category;
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
        return [$node->_lft, $node->_rgt, $node->parent_id, $node->depth];
    }

    public function testGetsNodeData(): void
    {
        $data = Category::getNodeData(3);

        $this->assertEquals(['_lft' => 3, '_rgt' => 4, 'depth' => 2], $data);
    }

    public function testGetsPlainNodeData(): void
    {
        $data = Category::getPlainNodeData(3);

        $this->assertEquals([3, 4], $data);
    }

    public function testZeroHeightGapDoesNotIssueAQuery(): void
    {
        DB::flushQueryLog();

        $this->assertSame(0, Category::query()->makeGap(5, 0));
        $this->assertSame([], DB::getQueryLog());
    }

    public function testLowLevelMoveDerivesDepthWhenTheCallerOmitsIt(): void
    {
        $this->assertSame(0, Category::query()->depthForPosition(21));
        $this->assertSame(3, Category::query()->depthForPosition(12));

        Category::query()->moveNode(2, 12);

        $this->assertSame(3, Category::findOrFail(2)->getDepth());
        $this->assertSame(4, Category::findOrFail(3)->getDepth());
    }

    public function testLowLevelMoveRejectsIncompleteNodeData(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Node data for [Hypervel\Tests\NestedSet\Models\Category] must contain [_lft], [_rgt], and [depth].',
        );

        Category::query()->moveNode(2, 12, nodeData: [
            '_lft' => 2,
            '_rgt' => 7,
        ]);
    }

    public function testFirstMoveDoesNotRefreshAFreshSourceNode(): void
    {
        $node = Category::findOrFail(3);
        $parent = Category::findOrFail(5);
        DB::flushQueryLog();

        $node->appendToNode($parent)->save();

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testReceivesValidValuesWhenAppendedTo(): void
    {
        $node = new Category(['name' => 'test']);
        $root = Category::root();

        $accepted = [$root->_rgt, $root->_rgt + 1, $root->id, $root->depth + 1];

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
        $this->assertEquals([$root->_lft + 1, $root->_lft + 2, $root->id, $root->depth + 1], $this->nodeValues($node));
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
        $this->assertEquals([$target->_rgt + 1, $target->_rgt + 2, $target->parent->id, $target->depth], $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isSiblingOf($target));
    }

    public function testInsertAfterRefreshesTargetAndInvalidatesStructuralRelations(): void
    {
        $node = Category::with(['parent', 'siblings'])->findOrFail(3);
        $target = Category::with(['parent', 'siblings'])->findOrFail(9);

        $this->assertTrue($node->insertAfterNode($target));

        $storedTarget = Category::findOrFail(9);

        $this->assertSame($storedTarget->getLft(), $target->getLft());
        $this->assertSame($storedTarget->getRgt(), $target->getRgt());
        $this->assertFalse($node->relationLoaded('parent'));
        $this->assertFalse($node->relationLoaded('siblings'));
        $this->assertFalse($target->relationLoaded('parent'));
        $this->assertFalse($target->relationLoaded('siblings'));
    }

    public function testReceivesValidValuesWhenInsertedBefore(): void
    {
        $target = $this->findCategory('apple');
        $node = new Category(['name' => 'test']);
        $node->beforeNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals([$target->_lft, $target->_lft + 1, $target->parent->id, $target->depth], $this->nodeValues($node));
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

    #[DataProvider('structuralMovementCases')]
    public function testStructuralMovesMaintainSubtreeDepth(
        string $method,
        string $nodeName,
        string $targetName,
        int $expectedDepth,
        ?string $childName,
    ): void {
        $node = $this->findCategory($nodeName);
        $target = $this->findCategory($targetName);

        if ($method === 'append') {
            $target->appendNode($node);
        } else {
            $node->insertBeforeNode($target);
        }

        $this->assertSame($expectedDepth, $node->getDepth());

        if ($childName !== null) {
            $this->assertSame($expectedDepth + 1, $this->findCategory($childName)->getDepth());
        }

        $this->assertTreeNotBroken();
    }

    public static function structuralMovementCases(): array
    {
        return [
            'append level up' => ['append', 'samsung', 'store', 1, 'galaxy'],
            'append same level' => ['append', 'samsung', 'notebooks', 2, 'galaxy'],
            'append level down' => ['append', 'notebooks', 'samsung', 3, 'apple'],
            'insert before level up' => ['before', 'samsung', 'notebooks', 1, 'galaxy'],
            'insert before same level' => ['before', 'samsung', 'sony', 2, 'galaxy'],
            'insert before level down' => ['before', 'notebooks', 'galaxy', 3, 'apple'],
        ];
    }

    public function testBeforeNodeDerivesDepthWhenTheTargetDepthWasNotSelected(): void
    {
        $node = $this->findCategory('samsung');
        $target = Category::query()
            ->select(['id', 'name', '_lft', '_rgt', 'parent_id'])
            ->findOrFail(3);

        $node->insertBeforeNode($target);

        $this->assertSame(2, Category::findOrFail($node->getKey())->getDepth());
        $this->assertSame(3, $this->findCategory('galaxy')->getDepth());
        $this->assertTreeNotBroken();
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
        $this->expectExceptionMessage('Node must not be a descendant.');

        $node = $this->findCategory('notebooks');

        $node->prependToNode($node)->save();
    }

    public function testStructuralTargetsMustHavePositiveStoredBounds(): void
    {
        $target = $this->findCategory('apple')
            ->setLft(0)
            ->setRgt(0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Node must be part of a tree.');

        (new Category(['name' => 'test']))->appendToNode($target);
    }

    public function testWithoutRootWorks(): void
    {
        $result = Category::withoutRoot()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function testStructuralReadQueriesQualifyTheirColumnsAfterJoins(): void
    {
        $query = Category::query()
            ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
            ->select('categories.id');

        $this->assertSame(
            [1, 11],
            (clone $query)->whereIsRoot()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [2, 3, 4, 5, 6, 7, 8, 9, 10],
            (clone $query)->withoutRoot()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [2, 3, 4, 5, 6, 7, 8, 9, 10],
            (clone $query)->hasParent()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [3, 4, 6, 8, 9, 10, 11],
            (clone $query)->whereIsLeaf()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [1, 2, 5, 7],
            (clone $query)->hasChildren()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [1, 2, 3, 4],
            (clone $query)->whereIsBefore(5)->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [6, 7, 8, 9, 10, 11],
            (clone $query)->whereIsAfter(5)->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
            (clone $query)->defaultOrder()->pluck('id')->all(),
        );

        $node = Category::findOrFail(5);

        $this->assertSame(
            [6, 7, 8, 9, 10, 11],
            $node->nextNodes()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [1, 2, 3, 4],
            $node->prevNodes()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [5],
            Category::findOrFail(2)
                ->nextSiblings()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [2],
            $node->prevSiblings()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [2],
            $node->siblings()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            [2, 5],
            $node->siblingsAndSelf()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
    }

    public function testDefaultOrderClearsPreviousOrderBindings(): void
    {
        $ids = Category::query()
            ->orderByRaw('case when name = ? then 0 else 1 end', ['apple'])
            ->defaultOrder()
            ->where('name', '=', 'store')
            ->pluck('id')
            ->all();

        $this->assertSame([1], $ids);
    }

    public function testDefaultOrderClearsPreviousUnionOrderBindings(): void
    {
        $ids = Category::query()
            ->select(['id', '_lft'])
            ->whereKey(1)
            ->union(Category::query()->select(['id', '_lft'])->whereKey(3))
            ->orderByRaw('case when name = ? then 0 else 1 end', ['apple'])
            ->defaultOrder()
            ->get()
            ->pluck('id')
            ->all();

        $this->assertSame([1, 3], $ids);
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

        $node = new Category;
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
        CarbonImmutable::setTestNow('2025-07-03 12:00:00');

        $root = Category::root();

        $samsung = $this->findCategory('samsung');
        $samsung->delete();

        $this->assertTreeNotBroken();
        $this->assertNull($this->findCategory('galaxy'));

        CarbonImmutable::setTestNow('2025-07-03 12:00:01');

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

    public function testRestoredNodeDoesNotRetainItsPreviousDeletionTimestamp(): void
    {
        $node = $this->findCategory('mobile');
        $node->delete();

        $node = $this->findCategory('mobile', true);
        $node->restore();

        $this->assertNull($node->deleted_at);

        $node->name = 'restored mobile';
        $node->save();

        $this->assertNotNull($this->findCategory('restored mobile'));
    }

    public function testReentrantRestoreUsesEachNodesExactPreviousDeletionTimestamp(): void
    {
        CarbonImmutable::setTestNow('2025-07-03 12:00:00');
        $this->findCategory('notebooks')->delete();

        CarbonImmutable::setTestNow('2025-07-03 12:00:01');
        $this->findCategory('samsung')->delete();

        CarbonImmutable::setTestNow('2025-07-03 12:00:02');
        $this->findCategory('mobile')->delete();

        $nestedRestore = false;

        Category::restoring(function (Category $node) use (&$nestedRestore): void {
            if ($node->getKey() !== 5 || $nestedRestore) {
                return;
            }

            $nestedRestore = true;
            Category::withTrashed()->findOrFail(2)->restore();
        });

        Category::withTrashed()->findOrFail(5)->restore();

        $this->assertNotNull($this->findCategory('apple'));
        $this->assertNotNull($this->findCategory('nokia'));
        $this->assertNull($this->findCategory('samsung'));
    }

    public function testSoftDeletedNodeIsDeletedWhenParentIsDeleted(): void
    {
        $this->findCategory('samsung')->delete();

        $this->findCategory('mobile')->forceDelete();

        $this->assertTreeNotBroken();

        $this->assertNull($this->findCategory('samsung', true));
        $this->assertNull($this->findCategory('sony'));
    }

    public function testEventedDescendantDeletionRunsChildrenFirstInChunks(): void
    {
        $deleting = [];

        EventedCategoryModel::deleting(function (EventedCategoryModel $model) use (&$deleting): void {
            $deleting[] = $model->getKey();
        });

        EventedCategoryModel::findOrFail(5)->delete();

        $this->assertSame([5, 10, 9, 8, 7, 6], $deleting);

        foreach ([5, 6, 7, 8, 9, 10] as $id) {
            $this->assertNotNull(EventedCategoryModel::withTrashed()->findOrFail($id)->deleted_at);
        }
    }

    public function testEventedDescendantDeletionPropagatesVetoesForTransactionRollback(): void
    {
        EventedCategoryModel::deleting(
            fn (EventedCategoryModel $model) => $model->getKey() === 8 ? false : null,
        );

        try {
            DB::transaction(fn () => EventedCategoryModel::findOrFail(5)->delete());
            $this->fail('Expected the descendant deletion veto to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf(
                    'Deleting nested set descendant [%s] with key [8] was vetoed.',
                    EventedCategoryModel::class,
                ),
                $exception->getMessage(),
            );
        }

        foreach ([5, 6, 7, 8, 9, 10] as $id) {
            $this->assertNull(EventedCategoryModel::withTrashed()->findOrFail($id)->deleted_at);
        }
    }

    public function testEventedForceDeletionIncludesTrashedDescendantsAndClosesTheGap(): void
    {
        EventedCategoryModel::findOrFail(8)->delete();

        $deleting = [];

        EventedCategoryModel::deleting(function (EventedCategoryModel $model) use (&$deleting): void {
            $deleting[] = $model->getKey();
        });

        EventedCategoryModel::findOrFail(5)->forceDelete();

        $this->assertSame([5, 10, 9, 8, 7, 6], $deleting);
        $this->assertSame(8, EventedCategoryModel::findOrFail(1)->getRgt());

        foreach ([5, 6, 7, 8, 9, 10] as $id) {
            $this->assertNull(EventedCategoryModel::withTrashed()->find($id));
        }
    }

    public function testFailsToSaveNodeUntilParentIsSaved(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Node must be part of a tree.');

        $node = new Category(['name' => 'Node']);
        $parent = new Category(['name' => 'Parent']);

        $node->appendToNode($parent)->save();
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

    public function testPredicatesRequirePersistedRowsAndUseLogicalRowIdentity(): void
    {
        $root = Category::findOrFail(1);
        $parent = Category::findOrFail(2);
        $child = Category::findOrFail(3);
        $sibling = Category::findOrFail(4);
        $unsaved = new Category([
            'parent_id' => $parent->getKey(),
            '_lft' => $child->getLft(),
            '_rgt' => $child->getRgt(),
        ]);

        $sameRow = new Category;
        $sameRow->setRawAttributes($child->getAttributes(), true);
        $sameRow->exists = true;
        $sameRow->setConnection($child->getConnection()->getName());

        $this->assertTrue($child->isDescendantOf($root));
        $this->assertTrue($root->isAncestorOf($child));
        $this->assertTrue($child->isChildOf($parent));
        $this->assertTrue($child->isSiblingOf($sibling));
        $this->assertTrue($child->isSelfOrDescendantOf($sameRow));
        $this->assertTrue($child->isSelfOrAncestorOf($sameRow));
        $this->assertFalse($child->isDescendantOf($sameRow));
        $this->assertFalse($child->isAncestorOf($sameRow));
        $this->assertFalse($child->isSiblingOf($sameRow));
        $this->assertFalse($unsaved->isDescendantOf($root));
        $this->assertFalse($unsaved->isSelfOrDescendantOf($child));
        $this->assertFalse($unsaved->isChildOf($parent));
        $this->assertFalse($unsaved->isSiblingOf($sibling));
    }

    public function testPredicatesTreatZeroAsARealPersistedParentKey(): void
    {
        $parent = new Category;
        $parent->setRawAttributes([
            'id' => 0,
            '_lft' => 1,
            '_rgt' => 4,
            'parent_id' => null,
            'depth' => 0,
        ], true);
        $parent->exists = true;

        $child = new Category;
        $child->setRawAttributes([
            'id' => 12,
            '_lft' => 2,
            '_rgt' => 3,
            'parent_id' => '0',
            'depth' => 1,
        ], true);
        $child->exists = true;

        $this->assertTrue($child->isChildOf($parent));
        $this->assertTrue($child->isDescendantOf($parent));
    }

    public function testSiblingsAreRealLazyLoadableRelations(): void
    {
        $node = $this->findCategory('samsung');

        $this->assertEquals([6, 9, 10], $this->getAll($node->siblings->pluck('id')));
        $this->assertTrue($node->relationLoaded('siblings'));

        $node->unsetRelation('siblings');

        $this->assertEquals([6, 7, 9, 10], $this->getAll($node->siblingsAndSelf->pluck('id')));
        $this->assertTrue($node->relationLoaded('siblingsAndSelf'));
    }

    public function testSiblingsEagerLoadWithExactParentBuckets(): void
    {
        $nodes = Category::whereIn('id', [3, 7])
            ->defaultOrder()
            ->get();

        $nodes->load(['siblings', 'siblingsAndSelf']);

        $this->assertEquals([4], $this->getAll($nodes->find(3)->siblings->pluck('id')));
        $this->assertEquals([3, 4], $this->getAll($nodes->find(3)->siblingsAndSelf->pluck('id')));
        $this->assertEquals([6, 9, 10], $this->getAll($nodes->find(7)->siblings->pluck('id')));
        $this->assertEquals([6, 7, 9, 10], $this->getAll($nodes->find(7)->siblingsAndSelf->pluck('id')));
    }

    public function testRootSiblingsSupportEagerAndExistenceQueries(): void
    {
        $nodes = Category::whereIn('id', [1, 11])
            ->defaultOrder()
            ->get();

        $nodes->load('siblings');

        $this->assertEquals([11], $this->getAll($nodes->find(1)->siblings->pluck('id')));
        $this->assertEquals([1], $this->getAll($nodes->find(11)->siblings->pluck('id')));
        $this->assertEquals([1, 11], $this->getAll(
            Category::has('siblings')->whereIn('id', [1, 11])->orderBy('id')->pluck('id'),
        ));
    }

    public function testSiblingsSupportExistenceCounts(): void
    {
        $this->assertEquals([3], $this->getAll(
            Category::has('siblings')->whereIn('id', [3, 8])->pluck('id'),
        ));

        $this->assertEquals([6, 7, 9, 10], $this->getAll(
            Category::has('siblings', '>', 2)->orderBy('id')->pluck('id'),
        ));
    }

    public function testSiblingsUseTheConfiguredParentColumn(): void
    {
        Schema::create('custom_parent_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('_lft')->default(0);
            $table->unsignedInteger('_rgt')->default(0);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedBigInteger('ancestor_id')->nullable();
        });

        DB::table('custom_parent_categories')->insert([
            ['id' => 1, '_lft' => 1, '_rgt' => 6, 'depth' => 0, 'ancestor_id' => null],
            ['id' => 2, '_lft' => 2, '_rgt' => 3, 'depth' => 1, 'ancestor_id' => 1],
            ['id' => 3, '_lft' => 4, '_rgt' => 5, 'depth' => 1, 'ancestor_id' => 1],
        ]);

        $node = CustomParentCategoryModel::with('siblings')->findOrFail(2);
        $relation = $node->siblings();

        $this->assertEquals([3], $node->siblings->pluck('id')->all());
        $this->assertSame('ancestor_id', $relation->getForeignKeyName());
        $this->assertSame('ancestor_id', $node->ancestors()->getForeignKeyName());
        $this->assertSame('ancestor_id', $node->descendants()->getForeignKeyName());
        $this->assertSame(
            'custom_parent_categories.ancestor_id',
            $relation->getQualifiedForeignKeyName(),
        );
        $this->assertSame(
            'custom_parent_categories.ancestor_id',
            $node->ancestors()->getQualifiedForeignKeyName(),
        );
        $this->assertSame(
            'custom_parent_categories.ancestor_id',
            $node->descendants()->getQualifiedForeignKeyName(),
        );
        $this->assertTrue(CustomParentCategoryModel::whereKey(2)->has('siblings')->exists());
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
        $this->assertNotSame($root, $root->children->first()->parent);
        $this->assertSame($root->getKey(), $root->children->first()->parent->getKey());
        $this->assertSame([], $root->children->first()->parent->getRelations());
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

    public function testTreeRootSelectionDistinguishesInferenceNullZeroAndEmptyString(): void
    {
        $nodes = new Collection([
            $this->makeCollectionNode(1, 1, 2, null),
            $this->makeCollectionNode(2, 3, 4, 0),
            $this->makeCollectionNode(3, 5, 6, ''),
        ]);

        $this->assertEquals([1], $nodes->toTree(null)->pluck('id')->all());
        $this->assertEquals([2], $nodes->toTree(0)->pluck('id')->all());
        $this->assertEquals([3], $nodes->toTree('')->pluck('id')->all());
        $this->assertEquals([1], $nodes->toTree()->pluck('id')->all());

        $partial = new Collection([
            $this->makeCollectionNode(2, 3, 4, 0),
            $this->makeCollectionNode(3, 5, 6, 0),
        ]);

        $this->assertEquals([2, 3], $partial->toTree()->pluck('id')->all());
        $this->assertTrue($partial->toTree(null)->isEmpty());
    }

    public function testTreeRootSelectionSupportsUuidAndUlidKeys(): void
    {
        $uuid = '018f3a2b-0000-7000-8000-000000000001';
        $ulid = '01J9ZTR3WQ4Z78F7N4MFSRMK7H';
        $nodes = new Collection([
            $uuidRoot = $this->makeCollectionNode(
                $uuid,
                1,
                4,
                null,
                new StringKeyCategoryModel,
            ),
            $this->makeCollectionNode(
                '018f3a2b-0000-7000-8000-000000000002',
                2,
                3,
                $uuid,
                new StringKeyCategoryModel,
            ),
            $ulidRoot = $this->makeCollectionNode(
                $ulid,
                5,
                8,
                null,
                new StringKeyCategoryModel,
            ),
            $this->makeCollectionNode(
                '01J9ZTR3WQ4Z78F7N4MFSRMK7J',
                6,
                7,
                $ulid,
                new StringKeyCategoryModel,
            ),
        ]);

        $this->assertSame(
            ['018f3a2b-0000-7000-8000-000000000002'],
            $nodes->toTree($uuidRoot)->modelKeys(),
        );
        $this->assertSame(
            ['01J9ZTR3WQ4Z78F7N4MFSRMK7J'],
            $nodes->toTree($ulid)->modelKeys(),
        );
    }

    public function testLinkNodesClearsStaleRelationsAndUsesSharedRelationFreeParents(): void
    {
        $nodes = Category::defaultOrder()->get();
        $leaf = $nodes->find(8);

        $leaf->setRelation('parent', new Category(['name' => 'stale']));
        $leaf->setRelation('children', new Collection([new Category]));

        $nodes->linkNodes();

        $siblings = $nodes->whereIn('id', [6, 7, 9, 10])->values();
        $parent = $siblings->first()->parent;

        $this->assertTrue($leaf->relationLoaded('children'));
        $this->assertTrue($leaf->children->isEmpty());
        $this->assertSame(7, $leaf->parent->getKey());
        $this->assertSame([], $leaf->parent->getRelations());

        foreach ($siblings as $sibling) {
            $this->assertSame($parent, $sibling->parent);
        }
    }

    public function testLinkedTreeSerializesWithoutParentChildCycles(): void
    {
        $tree = Category::defaultOrder()->get()->toTree();
        $decoded = json_decode($tree->toJson(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded[0]['id']);
        $this->assertSame(1, $decoded[0]['children'][0]['parent']['id']);
        $this->assertArrayNotHasKey('children', $decoded[0]['children'][0]['parent']);
    }

    public function testFlatTreeHandlesDeepCollectionsIteratively(): void
    {
        $nodes = [];

        for ($id = 1; $id <= 2000; ++$id) {
            $nodes[] = $this->makeCollectionNode(
                $id,
                $id,
                4001 - $id,
                $id === 1 ? null : $id - 1,
            );
        }

        $flat = (new Collection($nodes))->toFlatTree();

        $this->assertCount(2000, $flat);
        $this->assertSame(1, $flat->first()->getKey());
        $this->assertSame(2000, $flat->last()->getKey());
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

    public function testWhereIsBeforeAndAfterById(): void
    {
        $before = Category::whereIsBefore(4)
            ->defaultOrder()
            ->pluck('name')
            ->all();
        $after = Category::whereIsAfter(4)
            ->defaultOrder()
            ->pluck('name')
            ->all();

        $this->assertSame(['store', 'notebooks', 'apple'], $before);
        $this->assertSame(['mobile', 'nokia', 'samsung', 'galaxy', 'sony', 'lenovo', 'store_2'], $after);
    }

    public function testStructuralCoordinateLookupsRespectTheOuterSoftDeleteMode(): void
    {
        DB::table('categories')
            ->whereIn('id', [3, 5])
            ->update(['deleted_at' => CarbonImmutable::now()]);

        $this->assertSame(
            [1, 2, 3, 4],
            Category::withTrashed()->whereIsBefore(5)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [3],
            Category::onlyTrashed()->whereIsBefore(5)->pluck('id')->all(),
        );
        $this->assertSame(
            [1, 5, 7],
            Category::withTrashed()->whereAncestorOf(8)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [5],
            Category::onlyTrashed()->whereAncestorOf(8)->pluck('id')->all(),
        );
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
        $node = $this->findCategory('samsung');
        $node->saveAsRoot();

        $this->assertSame(0, Category::findOrFail($node->getKey())->getDepth());
        $this->assertSame(1, $this->findCategory('galaxy')->getDepth());
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
        $this->assertTreeNotBroken();
    }

    public function testCountsInvalidIntervals(): void
    {
        Category::whereKey(3)->update(['_lft' => 0]);

        $this->assertSame(1, Category::countErrors()['invalid_intervals']);
        $this->assertTrue(Category::isBroken());
    }

    public function testCountsDuplicateEndpointsAndSkipsAmbiguousCrossingAnalysis(): void
    {
        Category::whereKey(4)->update(['_lft' => 3]);

        $errors = Category::countErrors();

        $this->assertSame(1, $errors['duplicate_endpoints']);
        $this->assertSame(0, $errors['crossing_intervals']);
    }

    public function testCountsMissingEndpointRanges(): void
    {
        DB::table('categories')->where('id', 4)->delete();

        $this->assertSame(1, Category::countErrors()['missing_endpoints']);
    }

    public function testCountsCrossingIntervalsWithUniqueContiguousEndpoints(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'a', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'b', '_lft' => 2, '_rgt' => 5, 'parent_id' => null, 'depth' => 0],
            ['id' => 3, 'name' => 'c', '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'depth' => 0],
        ]);

        $errors = Category::countErrors();

        $this->assertSame(0, $errors['invalid_intervals']);
        $this->assertSame(0, $errors['duplicate_endpoints']);
        $this->assertSame(0, $errors['missing_endpoints']);
        $this->assertGreaterThan(0, $errors['crossing_intervals']);
    }

    public function testCountsMissingAndWrongParents(): void
    {
        Category::whereKey(4)->update(['parent_id' => 24]);
        Category::whereKey(8)->update(['parent_id' => 2]);

        $errors = Category::countErrors();

        $this->assertSame(1, $errors['missing_parent']);
        $this->assertSame(1, $errors['wrong_parent']);
    }

    public function testWrongParentAndWrongDepthCanBeReportedTogether(): void
    {
        Category::whereKey(8)->update(['parent_id' => 5]);

        $errors = Category::countErrors();

        $this->assertSame(1, $errors['wrong_parent']);
        $this->assertSame(1, $errors['wrong_depth']);
    }

    public function testIsBrokenShortCircuitsBeforeExpensiveChecks(): void
    {
        Category::whereKey(3)->update(['_lft' => 0]);
        DB::flushQueryLog();

        $this->assertTrue(Category::isBroken());
        $this->assertCount(1, DB::getQueryLog());
    }

    public function testCreatesNode(): void
    {
        $node = Category::create(['name' => 'test']);

        $this->assertEquals(23, $node->getLft());
    }

    public function testCreatesViaRelationship(): void
    {
        $node = $this->findCategory('apple');

        $node->children()->create(['name' => 'test']);

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
        $this->assertSame($node->getKey(), $node->children[0]->parent->getKey());
        $this->assertSame($node->children[0]->parent, $node->children[1]->parent);
        $this->assertSame([], $node->children[0]->parent->getRelations());
        $this->assertSame($node->getBounds(), $node->children[0]->parent->getBounds());
        json_decode($node->toJson(), true, flags: JSON_THROW_ON_ERROR);

        $node = $this->findCategory('test');

        $this->assertCount(2, $node->children);
        $this->assertEquals('test2', $node->children[0]->name);
    }

    public function testDescendantsOfNonExistingNode(): void
    {
        $node = new Category;

        $this->assertTrue($node->getDescendants()->isEmpty());
    }

    public function testWhereDescendantsOf(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(
            'No query results for model [Hypervel\Tests\NestedSet\Models\Category] 124',
        );

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

    public function testFixTreePromotesOrphanedBranchesToRoots(): void
    {
        Category::whereKey(2)->update(['parent_id' => 24]);

        Category::fixTree();

        $node = Category::find(2);

        $this->assertNull($node->getParentId());
        $this->assertSame(0, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testFixTreePublishesFreshnessBeforeChangingStoredBounds(): void
    {
        $root = Category::findOrFail(1);

        Category::whereKey(11)->update(['parent_id' => 1]);
        Category::fixTree();

        $root->refreshNode();

        $this->assertSame(22, $root->getRgt());
        $this->assertTreeNotBroken();
    }

    public function testFixTreeIgnoresVisibilityGlobalScopes(): void
    {
        $this->assertNull(GloballyScopedCategoryModel::find(8));

        Category::whereKey(8)->update(['_lft' => 999]);

        GloballyScopedCategoryModel::fixTree();

        $this->assertTreeNotBroken();
        $this->assertTrue(Category::find(8)->isDescendantOf(Category::find(7)));
    }

    public function testFixTreeSelectsExplicitObserverColumns(): void
    {
        $names = [];

        Category::saving(function (Category $model) use (&$names): void {
            $names[] = $model->name;
        });

        Category::whereKey(8)->update(['_lft' => 11]);

        Category::fixTree(extraColumns: ['name']);

        $this->assertNotEmpty($names);
        $this->assertNotContains(null, $names);
        $this->assertTreeNotBroken();
    }

    public function testFixTreeVetoRollsBackEarlierRepairWrites(): void
    {
        Category::whereKey(2)->update(['parent_id' => null]);

        $before = DB::table('categories')
            ->orderBy('id')
            ->get(['id', '_lft', '_rgt', 'parent_id', 'depth'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $saves = 0;
        $vetoedKey = null;

        Category::saving(function (Category $model) use (&$saves, &$vetoedKey): ?bool {
            if (++$saves !== 2) {
                return null;
            }

            $vetoedKey = $model->getKey();

            return false;
        });

        try {
            DB::transaction(fn (): int => Category::fixTree());
            $this->fail('Expected the repair veto to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf(
                    'Saving nested set node [%s] with key [%s] during repair was vetoed.',
                    Category::class,
                    $vetoedKey,
                ),
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            $before,
            DB::table('categories')
                ->orderBy('id')
                ->get(['id', '_lft', '_rgt', 'parent_id', 'depth'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );
    }

    public function testFixTreeRepairsDeepParentChainsIteratively(): void
    {
        DB::table('categories')->delete();

        $rows = [];
        $count = 200;

        for ($id = 1; $id <= $count; ++$id) {
            $rows[] = [
                'id' => $id,
                'name' => 'node ' . $id,
                '_lft' => 0,
                '_rgt' => 0,
                'parent_id' => $id === 1 ? null : $id - 1,
                'depth' => 0,
            ];
        }

        DB::table('categories')->insert($rows);

        Category::fixTree();

        $root = Category::find(1);
        $leaf = Category::find($count);

        $this->assertSame([1, $count * 2], $root->getBounds());
        $this->assertSame([$count, $count + 1], $leaf->getBounds());
        $this->assertSame($count - 1, $leaf->getDepth());
        $this->assertTreeNotBroken();
    }

    #[DataProvider('invalidSubtreeParents')]
    public function testFixSubtreePromotesInvalidBranchesToChildrenOfTheSuppliedRoot(
        int|string|null $parentId,
    ): void {
        Category::whereKey(6)->update(['parent_id' => $parentId]);

        Category::fixSubtree($root = Category::find(5));

        $node = Category::find(6);

        $this->assertSame($root->getKey(), $node->getParentId());
        $this->assertSame($root->getDepth() + 1, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public static function invalidSubtreeParents(): array
    {
        return [
            'missing parent' => [99],
            'parent outside subtree' => [1],
            'null parent' => [null],
        ];
    }

    public function testFixSubtreeBreaksParentCyclesWithoutCorruptingIntervals(): void
    {
        Category::whereKey(7)->update(['parent_id' => 8]);
        Category::whereKey(8)->update(['parent_id' => 7]);

        Category::fixSubtree(Category::find(5));

        $this->assertSame(5, Category::find(7)->getParentId());
        $this->assertSame(7, Category::find(8)->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testFixSubtreePersistsRowsShiftedByItsGapUpdate(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'first', '_lft' => 2, '_rgt' => 3, 'parent_id' => 1, 'depth' => 1],
            ['id' => 3, 'name' => 'second', '_lft' => 4, '_rgt' => 5, 'parent_id' => 1, 'depth' => 1],
        ]);

        Category::fixSubtree(Category::findOrFail(1));

        $this->assertSame([1, 6], Category::findOrFail(1)->getBounds());
        $this->assertSame([2, 3], Category::findOrFail(2)->getBounds());
        $this->assertSame([4, 5], Category::findOrFail(3)->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testFixSubtreeRejectsParentageOutsideItsStoredBounds(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'inside', '_lft' => 2, '_rgt' => 3, 'parent_id' => 1, 'depth' => 1],
            ['id' => 3, 'name' => 'outside', '_lft' => 6, '_rgt' => 7, 'parent_id' => 1, 'depth' => 1],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Nested set subtree for [Hypervel\Tests\NestedSet\Models\Category] with key [1] cannot be repaired because parentage crosses its stored bounds.',
        );

        Category::fixSubtree(Category::findOrFail(1));
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

    public function testDescendantEagerMatchingPreservesCustomOrder(): void
    {
        $nodes = Category::whereIn('id', [2, 5])->get();

        $nodes->load(['descendants' => fn ($query) => $query->orderBy('name')]);

        $this->assertEquals(
            ['apple', 'lenovo'],
            $this->getAll($nodes->find(2)->descendants->pluck('name')),
        );
        $this->assertEquals(
            ['galaxy', 'lenovo', 'nokia', 'samsung', 'sony'],
            $this->getAll($nodes->find(5)->descendants->pluck('name')),
        );
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
        $root = Category::findOrFail(1);

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

        $root->refreshNode();

        $this->assertSame(Category::findOrFail(1)->getRgt(), $root->getRgt());

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

    public function testRebuildSubtreePersistsRowsShiftedByItsGapUpdate(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'first', '_lft' => 2, '_rgt' => 3, 'parent_id' => 1, 'depth' => 1],
            ['id' => 3, 'name' => 'second', '_lft' => 4, '_rgt' => 5, 'parent_id' => 1, 'depth' => 1],
        ]);

        Category::rebuildSubtree(Category::findOrFail(1), [
            ['id' => 2],
            ['id' => 3],
        ]);

        $this->assertSame([1, 6], Category::findOrFail(1)->getBounds());
        $this->assertSame([2, 3], Category::findOrFail(2)->getBounds());
        $this->assertSame([4, 5], Category::findOrFail(3)->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testRebuildSubtreeRejectsParentageOutsideItsStoredBounds(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => 2, 'name' => 'inside', '_lft' => 2, '_rgt' => 3, 'parent_id' => 1, 'depth' => 1],
            ['id' => 3, 'name' => 'outside', '_lft' => 6, '_rgt' => 7, 'parent_id' => 1, 'depth' => 1],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Nested set subtree for [Hypervel\Tests\NestedSet\Models\Category] with key [1] cannot be repaired because parentage crosses its stored bounds.',
        );

        Category::rebuildSubtree(Category::findOrFail(1), []);
    }

    public function testRebuildSubtreeRepairsAnExistingOrphanedBranch(): void
    {
        Category::whereKey(8)->update(['parent_id' => 99]);

        Category::rebuildSubtree($root = Category::find(7), []);

        $node = Category::find(8);

        $this->assertSame($root->getKey(), $node->getParentId());
        $this->assertSame($root->getDepth() + 1, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testRebuildTreeVetoRollsBackEarlierModelWrites(): void
    {
        $before = Category::orderBy('id')->pluck('name', 'id')->all();
        $saves = 0;
        $vetoedKey = null;

        Category::saving(function (Category $model) use (&$saves, &$vetoedKey): ?bool {
            if (++$saves !== 2) {
                return null;
            }

            $vetoedKey = $model->getKey();

            return false;
        });

        try {
            DB::transaction(fn (): int => Category::rebuildTree([
                ['id' => 1, 'name' => 'updated store'],
                ['id' => 11, 'name' => 'updated second store'],
            ]));
            $this->fail('Expected the rebuild veto to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf(
                    'Saving nested set node [%s] with key [%s] during repair was vetoed.',
                    Category::class,
                    $vetoedKey,
                ),
                $exception->getMessage(),
            );
        }

        $this->assertSame($before, Category::orderBy('id')->pluck('name', 'id')->all());
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
        $this->expectExceptionMessage(
            'No query results for model [Hypervel\Tests\NestedSet\Models\Category] 24',
        );

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

    public function testExistenceQueriesRetainTheConnectionWithoutReplicatingModels(): void
    {
        $replicatedModels = [];

        Category::replicating(function (Category $model) use (&$replicatedModels): void {
            $replicatedModels[] = $model;
        });

        $parent = (new Category)->setConnection(DB::getDefaultConnection());
        $relation = $parent->descendants();
        $query = $relation->getRelationExistenceQuery(
            $relation->getQuery(),
            $parent->newQuery(),
        );

        $this->assertSame([], $replicatedModels);
        $this->assertSame($parent->getConnectionName(), $query->getModel()->getConnectionName());
    }

    public function testNestedWhereHasCorrelatesAgainstTheImmediatelyEnclosingRelation(): void
    {
        $this->assertSame(
            [1, 5],
            Category::whereHas(
                'descendants',
                fn ($query) => $query->whereHas('descendants'),
            )->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame(
            [1, 5, 7],
            Category::whereHas(
                'descendants',
                fn ($query) => $query->whereHas(
                    'ancestors',
                    fn ($query) => $query->where('name', 'samsung'),
                ),
            )->orderBy('id')->pluck('id')->all(),
        );
    }

    public function testAncestorsAreOrderedFromRootToParent(): void
    {
        $node = Category::with('ancestors')->findOrFail(8);

        $this->assertEquals(
            ['store', 'mobile', 'samsung'],
            $this->getAll($node->ancestors->pluck('name')),
        );
        $this->assertStringContainsString(
            'order by',
            strtolower($node->ancestors()->toSql()),
        );
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

    protected function makeCollectionNode(
        int|string $id,
        int $lft,
        int $rgt,
        int|string|null $parentId,
        ?Category $node = null,
    ): Category {
        $node ??= new Category;
        $node->setRawAttributes([
            'id' => $id,
            '_lft' => $lft,
            '_rgt' => $rgt,
            'parent_id' => $parentId,
            'depth' => 0,
        ], true);
        $node->exists = true;

        return $node;
    }
}

class CustomParentCategoryModel extends Model
{
    use HasNode;

    public bool $timestamps = false;

    protected ?string $table = 'custom_parent_categories';

    /**
     * Get the parent ID column name.
     */
    public function getParentIdName(): string
    {
        return 'ancestor_id';
    }
}

class EventedCategoryModel extends Category
{
    protected ?string $table = 'categories';

    /**
     * Determine whether descendant model events should be fired during deletion.
     */
    protected function shouldFireDescendantEvents(): bool
    {
        return true;
    }

    /**
     * Get the descendant deletion chunk size.
     */
    protected function getDescendantDeleteChunkSize(): int
    {
        return 2;
    }
}

class StringKeyCategoryModel extends Category
{
    public bool $incrementing = false;

    protected string $keyType = 'string';
}

class GloballyScopedCategoryModel extends Category
{
    protected ?string $table = 'categories';

    /**
     * Register the visibility scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(
            'visible',
            fn (EloquentBuilder $query) => $query->where('name', '<>', 'galaxy'),
        );
    }
}
