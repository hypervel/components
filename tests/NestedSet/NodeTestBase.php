<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\QueryException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\NestedSet\Eloquent\AncestorsRelation;
use Hypervel\NestedSet\Eloquent\BaseRelation;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\Eloquent\DescendantsRelation;
use Hypervel\NestedSet\Eloquent\SiblingsRelation;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Fixtures\Models\Category;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

#[ResetRefreshDatabaseState]
abstract class NodeTestBase extends TestCase
{
    use RefreshDatabase;

    /**
     * The fixture model class.
     *
     * @var class-string<Category>
     */
    protected string $category;

    /**
     * Get the fixture migration path.
     */
    abstract protected function getMigrationPath(): string;

    /**
     * Get the key of a seeded fixture category.
     */
    abstract protected function key(int $number): int|string;

    /**
     * Get the keys of seeded fixture categories.
     */
    protected function keys(int ...$numbers): array
    {
        return array_map($this->key(...), $numbers);
    }

    /**
     * Use a table prefix so raw nested set SQL is checked against prefixed tables.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set(
            'database.connections.' . $config->string('database.default') . '.prefix',
            'prfx_',
        );
    }

    /**
     * Get the migration options.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => $this->getMigrationPath(),
        ];
    }

    /**
     * Seed the fixture tree inside the test coroutine and transaction.
     */
    protected function afterRefreshingDatabase(): void
    {
        DB::enableQueryLog();

        DB::table('categories')
            ->insert($this->getMockCategories());
    }

    /**
     * Get the initial category records.
     */
    protected function getMockCategories(): array
    {
        return [
            ['id' => $this->key(1), 'name' => 'store', '_lft' => 1, '_rgt' => 20, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'notebooks', '_lft' => 2, '_rgt' => 7, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(3), 'name' => 'apple', '_lft' => 3, '_rgt' => 4, 'parent_id' => $this->key(2), 'depth' => 2],
            ['id' => $this->key(4), 'name' => 'lenovo', '_lft' => 5, '_rgt' => 6, 'parent_id' => $this->key(2), 'depth' => 2],
            ['id' => $this->key(5), 'name' => 'mobile', '_lft' => 8, '_rgt' => 19, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(6), 'name' => 'nokia', '_lft' => 9, '_rgt' => 10, 'parent_id' => $this->key(5), 'depth' => 2],
            ['id' => $this->key(7), 'name' => 'samsung', '_lft' => 11, '_rgt' => 14, 'parent_id' => $this->key(5), 'depth' => 2],
            ['id' => $this->key(8), 'name' => 'galaxy', '_lft' => 12, '_rgt' => 13, 'parent_id' => $this->key(7), 'depth' => 3],
            ['id' => $this->key(9), 'name' => 'sony', '_lft' => 15, '_rgt' => 16, 'parent_id' => $this->key(5), 'depth' => 2],
            ['id' => $this->key(10), 'name' => 'lenovo', '_lft' => 17, '_rgt' => 18, 'parent_id' => $this->key(5), 'depth' => 2],
            ['id' => $this->key(11), 'name' => 'store_2', '_lft' => 21, '_rgt' => 22, 'parent_id' => null, 'depth' => 0],
        ];
    }

    /**
     * Replace the seeded tree with the small rebuild query fixture.
     */
    private function resetRebuildQueryFixture(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => $this->key(1), 'name' => 'root', '_lft' => 1, '_rgt' => 8, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'branch', '_lft' => 2, '_rgt' => 3, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(3), 'name' => 'parent', '_lft' => 4, '_rgt' => 7, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(4), 'name' => 'child', '_lft' => 5, '_rgt' => 6, 'parent_id' => $this->key(3), 'depth' => 2],
        ]);
    }

    /**
     * Stop query logging on the test coroutine's connection.
     */
    protected function tearDownInCoroutine(): void
    {
        DB::flushQueryLog();
        DB::disableQueryLog();
    }

    /**
     * Assert that the tree has no structural errors.
     */
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
        ], $this->category::countErrors());
    }

    /**
     * Assert that the node's bounds match the stored row.
     */
    protected function assertNodeReceivesValidValues(Category $node): void
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

    /**
     * Find a category by name.
     */
    public function findCategory(string $name, bool $withTrashed = false): ?Category
    {
        $category = new $this->category;
        $query = $withTrashed ? $category->withTrashed() : $category->newQuery();

        return $query->whereName($name)->first();
    }

    /**
     * Get the node's structural column values.
     */
    protected function nodeValues(Category $node): array
    {
        return [$node->_lft, $node->_rgt, $node->parent_id, $node->depth];
    }

    /**
     * Get every persisted row of a fixture table in key order.
     */
    protected function persistedRows(string $table = 'categories'): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    public function testTreeNotBroken(): void
    {
        $this->assertTreeNotBroken();
        $this->assertFalse($this->category::isBroken());
    }

    public function testGetsNodeData(): void
    {
        $data = $this->category::getNodeData($this->key(3));

        $this->assertEquals(['_lft' => 3, '_rgt' => 4, 'depth' => 2], $data);
    }

    public function testGetsPlainNodeData(): void
    {
        $data = $this->category::getPlainNodeData($this->key(3));

        $this->assertEquals([3, 4], $data);
    }

    public function testZeroHeightGapDoesNotIssueAQuery(): void
    {
        DB::flushQueryLog();

        $this->assertSame(0, $this->category::query()->makeGap(5, 0));
        $this->assertSame([], DB::getQueryLog());
    }

    public function testLowLevelMoveDerivesDepthWhenTheCallerOmitsIt(): void
    {
        $retrieved = 0;

        $this->category::retrieved(function () use (&$retrieved): void {
            ++$retrieved;
        });

        $this->assertSame(0, $this->category::query()->depthForPosition(21));
        $this->assertSame(3, $this->category::query()->depthForPosition(12));

        $this->category::query()->moveNode($this->key(2), 12);

        // Depth lookups read plain rows instead of hydrating partial models.
        $this->assertSame(0, $retrieved);
        $this->assertSame(3, $this->category::findOrFail($this->key(2))->getDepth());
        $this->assertSame(4, $this->category::findOrFail($this->key(3))->getDepth());
    }

    #[DataProvider('invalidLowLevelNodeData')]
    public function testLowLevelMoveRejectsInvalidNodeData(array $nodeData): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Node data for [{$this->category}] must contain [_lft], [_rgt], and [depth].",
        );

        $this->category::query()->moveNode($this->key(2), 12, nodeData: $nodeData);
    }

    /**
     * Get node data missing a required structural value.
     */
    public static function invalidLowLevelNodeData(): array
    {
        return [
            'missing depth' => [['_lft' => 2, '_rgt' => 7]],
            'null left bound' => [['_lft' => null, '_rgt' => 7, 'depth' => 1]],
        ];
    }

    public function testMovePreparesEachParticipantAtTheSaveBoundary(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        $parent = $this->category::findOrFail($this->key(5));
        DB::flushQueryLog();

        $node->appendToNode($parent)->save();

        $this->assertSame(3, $this->countStructuralIdentityReloads());
    }

    public function testParticipantPreparationDoesNotFireRetrievedListeners(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        $parent = $this->category::findOrFail($this->key(5));
        $retrieved = 0;

        $this->category::retrieved(function () use (&$retrieved): void {
            ++$retrieved;
        });

        $node->appendToNode($parent)->save();
        $node->refreshNode();

        $this->assertSame(0, $retrieved);
        $this->assertTreeNotBroken();
    }

    public function testRolledBackMoveCanBeSavedAgain(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        $parent = $this->category::findOrFail($this->key(5));
        $veto = new LogicException('The move was vetoed.');
        $vetoed = false;
        $caught = null;

        $this->category::updating(function () use (&$vetoed): ?bool {
            if ($vetoed) {
                return null;
            }

            $vetoed = true;

            return false;
        });

        $node->appendToNode($parent);

        try {
            DB::transaction(function () use ($node, $veto): void {
                if (! $node->save()) {
                    throw $veto;
                }
            });
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        $this->assertSame($veto, $caught);
        $this->assertSame($this->key(2), $this->category::findOrFail($this->key(3))->getParentId());

        $this->assertTrue($node->save());

        $this->assertSame($this->key(5), $this->category::findOrFail($this->key(3))->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testMoveRolledBackAfterSavingCanBeQueuedAgain(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        $parent = $this->category::findOrFail($this->key(5));
        $rollback = new LogicException('Roll back the saved move.');
        $caught = null;

        try {
            DB::transaction(function () use ($node, $parent, $rollback): never {
                $this->assertTrue($node->appendToNode($parent)->save());

                throw $rollback;
            });
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        $this->assertSame($rollback, $caught);
        $this->assertSame($this->key(2), $this->category::findOrFail($this->key(3))->getParentId());

        $this->assertTrue($node->appendToNode($parent)->save());

        $persisted = $this->category::findOrFail($this->key(3));

        $this->assertSame($this->key(5), $persisted->getParentId());
        $this->assertSame($persisted->getBounds(), $node->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testSavingAgainFromTheCreatedListenerDoesNotReplayTheAction(): void
    {
        $listened = false;

        $this->category::created(function (Category $model) use (&$listened): void {
            if ($listened) {
                return;
            }

            $listened = true;
            $this->category::create(['name' => 'later root']);
            $model->name = 'renamed root';
            $model->save();
        });

        $root = $this->category::create(['name' => 'root']);
        $persisted = $this->category::findOrFail($root->getKey());

        $this->assertSame([23, 24], $persisted->getBounds());
        $this->assertSame('renamed root', $persisted->name);
        $this->assertSame([25, 26], $this->findCategory('later root')->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testActionQueuedByAnObserverWaitsForTheNextSave(): void
    {
        $parent = $this->category::findOrFail($this->key(5));
        $queued = false;

        $this->category::created(function (Category $model) use ($parent, &$queued): void {
            if (! $queued) {
                $queued = true;
                $model->appendToNode($parent);
            }
        });

        $node = new $this->category(['name' => 'queued']);
        $node->save();

        $this->assertNull($this->category::findOrFail($node->getKey())->getParentId());

        $this->assertTrue($node->save());

        $this->assertSame($this->key(5), $this->category::findOrFail($node->getKey())->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testMovingANewSourceDoesNotPreflightTheSource(): void
    {
        $parent = $this->category::findOrFail($this->key(5));
        DB::flushQueryLog();

        (new $this->category(['name' => 'new child']))
            ->appendToNode($parent)
            ->save();

        $this->assertSame(2, $this->countStructuralIdentityReloads());
        $this->assertTreeNotBroken();
    }

    public function testEveryMovePreparesEachParticipantOnlyOnce(): void
    {
        $firstSource = $this->category::findOrFail($this->key(3));
        $firstTarget = $this->category::findOrFail($this->key(5));
        $secondSource = $this->category::findOrFail($this->key(6));
        $secondTarget = $this->category::findOrFail($this->key(2));

        $firstSource->appendToNode($firstTarget)->save();
        DB::flushQueryLog();

        $secondSource->appendToNode($secondTarget)->save();

        $this->assertSame(3, $this->countStructuralIdentityReloads());
        $this->assertTreeNotBroken();
    }

    #[DataProvider('deletedStructuralParticipants')]
    public function testDeletedStructuralParticipantsFailBeforeWriting(string $participant): void
    {
        $source = $this->category::findOrFail($this->key(3));
        $target = $this->category::findOrFail($this->key(5));

        $this->category::query()->moveNode($this->key(11), 1);
        DB::table('categories')
            ->where('id', $participant === 'source' ? $source->getKey() : $target->getKey())
            ->delete();

        $columns = ['id', '_lft', '_rgt', 'parent_id', 'depth'];
        $before = DB::table('categories')
            ->orderBy('id')
            ->get($columns)
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $caught = null;

        try {
            $source->appendToNode($target)->save();
            $this->fail('Expected the deleted structural participant to be rejected.');
        } catch (ModelNotFoundException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame(
            $before,
            DB::table('categories')
                ->orderBy('id')
                ->get($columns)
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        );
    }

    /**
     * Get the structural participants that may be deleted before a write.
     */
    public static function deletedStructuralParticipants(): array
    {
        return [
            'source' => ['source'],
            'target' => ['target'],
        ];
    }

    public function testReceivesValidValuesWhenAppendedTo(): void
    {
        $node = new $this->category(['name' => 'test']);
        $root = $this->category::root();

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
        $root = $this->category::root();
        $node = new $this->category(['name' => 'test']);
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
        $node = new $this->category(['name' => 'test']);
        $node->afterNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals([$target->_rgt + 1, $target->_rgt + 2, $target->parent->id, $target->depth], $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isSiblingOf($target));
    }

    public function testInsertAfterRefreshesTargetAndInvalidatesStructuralRelations(): void
    {
        $node = $this->category::with(['parent', 'siblings'])->findOrFail($this->key(3));
        $target = $this->category::with(['parent', 'siblings'])->findOrFail($this->key(9));

        $this->assertTrue($node->insertAfterNode($target));

        $storedTarget = $this->category::findOrFail($this->key(9));

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
        $node = new $this->category(['name' => 'test']);
        $node->beforeNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals([$target->_lft, $target->_lft + 1, $target->parent->id, $target->depth], $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testCategoryMoveLevelUp(): void
    {
        $node = $this->findCategory('galaxy');
        $target = $this->findCategory('notebooks');

        $this->assertSame(1, $target->getDepth());
        $this->assertSame(3, $node->getDepth());

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertNodeReceivesValidValues($node);
        $this->assertTreeNotBroken();

        $this->assertSame(1, $target->getDepth());
        $this->assertSame(2, $node->getDepth());
    }

    public function testCategoryMoveLevelSame(): void
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('notebooks');

        $this->assertSame(1, $target->getDepth());
        $this->assertSame(2, $node->getDepth());

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertSame(1, $target->getDepth());
        $this->assertSame(2, $node->getDepth());
    }

    public function testCategoryMoveLevelDown(): void
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('samsung');

        $this->assertSame(2, $target->getDepth());
        $this->assertSame(2, $node->getDepth());

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertSame(2, $target->getDepth());
        $this->assertSame(3, $node->getDepth());
    }

    public function testCategoryMoveBeforeUp(): void
    {
        $node = $this->findCategory('galaxy');
        $target = $this->findCategory('apple');

        $this->assertSame(2, $target->getDepth());
        $this->assertSame(3, $node->getDepth());

        $node->insertBeforeNode($target);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertSame(2, $target->getDepth());
        $this->assertSame(2, $node->getDepth());
    }

    public function testCategoryMoveBeforeSame(): void
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('samsung');

        $this->assertSame(2, $target->getDepth());
        $this->assertSame(2, $node->getDepth());

        $node->insertBeforeNode($target);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertSame(2, $target->getDepth());
        $this->assertSame(2, $node->getDepth());
    }

    public function testCategoryMoveBeforeDown(): void
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('galaxy');

        $this->assertSame(3, $target->getDepth());
        $this->assertSame(2, $node->getDepth());

        $node->insertBeforeNode($target);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertSame(3, $target->getDepth());
        $this->assertSame(3, $node->getDepth());
    }

    #[DataProvider('staleSiblingMovements')]
    public function testSiblingMovementRereadsAfterAnEarlierStructuralShift(
        string $method,
        int $nodeId,
    ): void {
        $node = $this->category::findOrFail($this->key($nodeId));

        $this->category::query()->moveNode($this->key(11), 1);

        $this->assertTrue($node->{$method}());
        $this->assertSame(
            $this->keys(4, 3),
            $this->category::where('parent_id', $this->key(2))->defaultOrder()->pluck('id')->all(),
        );
        $this->assertTreeNotBroken();
    }

    /**
     * Get sibling movements made after an earlier structural shift.
     */
    public static function staleSiblingMovements(): array
    {
        return [
            'move the first sibling down' => ['down', 3],
            'move the second sibling up' => ['up', 4],
        ];
    }

    #[DataProvider('structuralMovementCases')]
    public function testStructuralMovesMaintainSubtreeDepth(
        string $method,
        string $nodeName,
        string $targetName,
        int $expectedDepth,
        string $childName,
    ): void {
        $node = $this->findCategory($nodeName);
        $target = $this->findCategory($targetName);

        if ($method === 'append') {
            $target->appendNode($node);
        } else {
            $node->insertBeforeNode($target);
        }

        $this->assertSame($expectedDepth, $node->getDepth());
        $this->assertSame($expectedDepth, $this->findCategory($nodeName)->getDepth());
        $this->assertSame($expectedDepth + 1, $this->findCategory($childName)->getDepth());
        $this->assertTreeNotBroken();
    }

    /**
     * Get structural moves with their expected subtree depth.
     */
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
        $target = $this->category::query()
            ->select(['id', 'name', '_lft', '_rgt', 'parent_id'])
            ->findOrFail($this->key(3));

        $node->insertBeforeNode($target);

        $this->assertSame(2, $this->category::findOrFail($node->getKey())->getDepth());
        $this->assertSame(3, $this->findCategory('galaxy')->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testAppendNewNodeDerivesDepthFromHandPositionedParentWithoutLoadedDepth(): void
    {
        $parent = new $this->category;
        $parent->setRawAttributes([
            'id' => $this->key(7),
            '_lft' => 11,
            '_rgt' => 14,
            'parent_id' => $this->key(5),
        ]);

        $node = new $this->category(['name' => 'new phone']);
        $node->appendToNode($parent)->save();
        $node = $node->fresh();

        $this->assertSame($this->key(7), $node->getParentId());
        $this->assertSame(3, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testAppendExistingSubtreeRefreshesParentWithoutSelectedDepth(): void
    {
        $node = $this->findCategory('notebooks');
        $parent = $this->category::query()
            ->select(['id', '_lft', '_rgt', 'parent_id'])
            ->findOrFail($this->key(7));

        $node->appendToNode($parent)->save();

        $this->assertSame(3, $this->category::findOrFail($this->key(2))->getDepth());
        $this->assertSame(4, $this->category::findOrFail($this->key(3))->getDepth());
        $this->assertSame(4, $this->category::findOrFail($this->key(4))->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testMovingPartiallySelectedSourceRefreshesItsStructuralData(): void
    {
        $node = $this->category::query()
            ->select(['id', 'parent_id'])
            ->findOrFail($this->key(2));

        $node->appendToNode($this->findCategory('samsung'))->save();

        $this->assertSame(3, $this->category::findOrFail($this->key(2))->getDepth());
        $this->assertSame(4, $this->category::findOrFail($this->key(3))->getDepth());
        $this->assertSame(4, $this->category::findOrFail($this->key(4))->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testPersistedMutationParticipantRequiresASelectedKey(): void
    {
        $node = $this->category::query()
            ->select(['_lft', '_rgt', 'depth', 'parent_id'])
            ->whereKey($this->key(2))
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set model [{$this->category}] requires the [id] column to be selected.",
        );

        $node->appendToNode($this->category::findOrFail($this->key(5)))->save();
    }

    public function testRefreshingPersistedNodeRequiresASelectedKey(): void
    {
        $node = $this->category::query()
            ->select(['_lft', '_rgt', 'depth'])
            ->whereKey($this->key(3))
            ->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set model [{$this->category}] requires the [id] column to be selected.",
        );

        $node->refreshNode();
    }

    public function testBeforeNodeRefreshesMissingTargetParentage(): void
    {
        $node = $this->category::findOrFail($this->key(6));
        $target = $this->category::query()
            ->select(['id', '_lft', '_rgt', 'depth'])
            ->findOrFail($this->key(3));

        $node->insertBeforeNode($target);

        $this->assertSame($this->key(2), $this->category::findOrFail($this->key(6))->getParentId());
        $this->assertTrue($this->category::findOrFail($this->key(6))->isSiblingOf($this->category::findOrFail($this->key(3))));
        $this->assertTreeNotBroken();
    }

    public function testDeferredSiblingInsertionRevalidatesTheTargetsCurrentParent(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        $target = $this->category::findOrFail($this->key(9));

        $node->beforeNode($target);
        $target->appendToNode($this->category::findOrFail($this->key(2)))->save();
        $node->save();

        $node = $this->category::findOrFail($this->key(3));
        $target = $this->category::findOrFail($this->key(9));

        $this->assertSame($this->key(2), $node->getParentId());
        $this->assertSame($target->getParentId(), $node->getParentId());
        $this->assertTrue($node->isSiblingOf($target));
        $this->assertTreeNotBroken();
    }

    public function testFailsToInsertIntoChild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Node must not be a descendant.');

        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->afterNode($target)->save();
    }

    public function testFailsToAppendIntoItself(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Node must not be a descendant.');

        $node = $this->findCategory('notebooks');

        $node->appendToNode($node)->save();
    }

    public function testFailsToPrependIntoItself(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Node must not be a descendant.');

        $node = $this->findCategory('notebooks');

        $node->prependToNode($node)->save();
    }

    public function testStructuralTargetsMustHavePositiveStoredBounds(): void
    {
        $target = $this->findCategory('apple')
            ->setLft(0)
            ->setRgt(0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Node must be part of a tree.');

        (new $this->category(['name' => 'test']))->appendToNode($target);
    }

    public function testRefreshNodeReadsCurrentLowLevelStructuralWrites(): void
    {
        $node = $this->category::findOrFail($this->key(11));

        $this->category::query()->makeGap(21, 2);
        $node->refreshNode();

        $this->assertSame([23, 24], $node->getBounds());

        $this->category::query()->moveNode($this->key(11), 1);
        $node->refreshNode();

        $this->assertSame([1, 2], $node->getBounds());
    }

    public function testRawNodePendingActionSaves(): void
    {
        $node = new $this->category(['name' => 'raw']);

        $node->rawNode(23, 24, null, 0);

        $this->assertTrue($node->save());
        $this->assertSame([23, 24], $node->fresh()->getBounds());
    }

    public function testRawNodeStoresAMissingDepthAsZero(): void
    {
        $node = new $this->category(['name' => 'raw']);

        $node->rawNode(23, 24, null, null);

        $this->assertTrue($node->save());
        $this->assertSame(0, $node->fresh()->getDepth());
    }

    public function testRawNodeWithCompleteScopeDoesNotPreflightPersistedIdentity(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        DB::flushQueryLog();

        $node->rawNode(
            $node->getLft(),
            $node->getRgt(),
            $node->getParentId(),
            $node->getDepth() + 1,
        )->save();

        $this->assertSame(0, $this->countStructuralIdentityReloads());
        $this->assertSame(
            $node->getDepth(),
            $this->category::findOrFail($node->getKey())->getDepth(),
        );
    }

    #[DataProvider('structuralMutationMethods')]
    public function testStructuralMutationTargetsMustUseTheSameTree(string $method): void
    {
        $connection = DB::getDefaultConnection();

        config([
            'database.connections.nested_set_other' => config(
                "database.connections.{$connection}",
            ),
        ]);

        $source = $this->findCategory('apple');
        $target = $this->findCategory('notebooks')
            ->setConnection('nested_set_other');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Nodes must be in the same tree.');

        $source->{$method}($target);
    }

    /**
     * Get the structural mutation methods that take a target node.
     */
    public static function structuralMutationMethods(): array
    {
        return [
            'append' => ['appendToNode'],
            'prepend' => ['prependToNode'],
            'before' => ['beforeNode'],
            'after' => ['afterNode'],
        ];
    }

    public function testStructuralPredicatesRequireTheSameStore(): void
    {
        $connection = DB::getDefaultConnection();

        config([
            'database.connections.nested_set_other' => config(
                "database.connections.{$connection}",
            ),
        ]);

        $child = $this->findCategory('apple');
        $parent = $this->findCategory('notebooks')
            ->setConnection('nested_set_other');
        $sibling = $this->category::findOrFail($this->key(4))
            ->setConnection('nested_set_other');

        $this->assertFalse($child->isChildOf($parent));
        $this->assertFalse($child->isDescendantOf($parent));
        $this->assertFalse($child->isSiblingOf($sibling));
    }

    public function testWithoutRootWorks(): void
    {
        $this->assertSame(
            $this->keys(2, 3, 4, 5, 6, 7, 8, 9, 10),
            $this->category::withoutRoot()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function testStructuralReadQueriesQualifyTheirColumnsAfterJoins(): void
    {
        $query = $this->category::query()
            ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
            ->select('categories.id');

        $this->assertSame(
            $this->keys(3, 4, 6, 8, 9, 10, 11),
            (clone $query)->whereIsLeaf()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 2, 5, 7),
            (clone $query)->hasChildren()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 2, 3, 4),
            (clone $query)->whereIsBefore($this->key(5))->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(6, 7, 8, 9, 10, 11),
            (clone $query)->whereIsAfter($this->key(5))->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11),
            (clone $query)->defaultOrder()->pluck('id')->all(),
        );
        $this->assertSame(
            ['_lft' => 3, '_rgt' => 4, 'depth' => 2],
            (clone $query)->getNodeData($this->key(3)),
        );
        $this->assertSame([3, 4], (clone $query)->getPlainNodeData($this->key(3)));

        $node = $this->category::findOrFail($this->key(5));

        $this->assertSame(
            $this->keys(6, 7, 8, 9, 10, 11),
            $node->nextNodes()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(1, 2, 3, 4),
            $node->prevNodes()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(5),
            $this->category::findOrFail($this->key(2))
                ->nextSiblings()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(2),
            $node->prevSiblings()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(2),
            $node->siblings()
                ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
                ->select('categories.id')
                ->orderBy('categories.id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(2, 5),
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
        $ids = $this->category::query()
            ->orderByRaw('case when name = ? then 0 else 1 end', ['apple'])
            ->defaultOrder()
            ->where('name', '=', 'store')
            ->pluck('id')
            ->all();

        $this->assertSame($this->keys(1), $ids);
    }

    public function testDefaultOrderClearsPreviousUnionOrderBindings(): void
    {
        $ids = $this->category::query()
            ->select(['id', '_lft'])
            ->whereKey($this->key(1))
            ->union($this->category::query()->select(['id', '_lft'])->whereKey($this->key(3)))
            ->orderByRaw('case when name = ? then 0 else 1 end', ['apple'])
            ->defaultOrder()
            ->get()
            ->pluck('id')
            ->all();

        $this->assertSame($this->keys(1, 3), $ids);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself(): void
    {
        $node = $this->findCategory('apple');
        $path = $node->ancestors()->pluck('name')->all();

        $this->assertEquals(['store', 'notebooks'], $path);
    }

    public function testGetsAncestorsByStatic(): void
    {
        $path = $this->category::ancestorsOf($this->key(3))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['store', 'notebooks'], $path);
    }

    public function testGetsAncestorsDirect(): void
    {
        $path = $this->category::find($this->key(8))->getAncestors()->pluck('id')->all();

        $this->assertEquals($this->keys(1, 5, 7), $path);
    }

    public function testGetRelationMethodsQueryFreshRowsWithoutReplacingLoadedRelations(): void
    {
        $node = $this->category::with(['ancestors', 'descendants', 'siblings'])->findOrFail($this->key(7));

        DB::table('categories')->whereIn('id', $this->keys(5, 8, 9))->update(['name' => 'renamed']);

        $this->assertContains('renamed', $node->getAncestors()->pluck('name')->all());
        $this->assertContains('renamed', $node->getDescendants()->pluck('name')->all());
        $this->assertContains('renamed', $node->getSiblings()->pluck('name')->all());
        $this->assertNotContains('renamed', $node->ancestors->pluck('name')->all());
        $this->assertNotContains('renamed', $node->descendants->pluck('name')->all());
        $this->assertNotContains('renamed', $node->siblings->pluck('name')->all());
    }

    public function testDescendants(): void
    {
        $node = $this->findCategory('mobile');
        $descendants = $node->descendants()->pluck('name')->all();
        $expected = ['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'];

        $this->assertEqualsCanonicalizing($expected, $descendants);

        $descendants = $node->getDescendants()->pluck('name')->all();

        $this->assertEquals(count($descendants), $node->getDescendantCount());
        $this->assertEqualsCanonicalizing($expected, $descendants);

        $descendants = $this->category::descendantsAndSelf($this->key(7))->pluck('name')->all();
        $expected = ['samsung', 'galaxy'];

        $this->assertEqualsCanonicalizing($expected, $descendants);
    }

    public function testDescendantsOfExcludesSelf(): void
    {
        $this->assertEqualsCanonicalizing(
            $this->keys(6, 7, 8, 9, 10),
            $this->category::descendantsOf($this->key(5))->pluck('id')->all(),
        );
    }

    public function testAncestorsAndSelfIncludesNode(): void
    {
        $this->assertEqualsCanonicalizing(
            $this->keys(1, 5, 7, 8),
            $this->category::ancestorsAndSelf($this->key(8))->pluck('id')->all(),
        );
    }

    public function testWhereNotDescendantOfExcludesSubtree(): void
    {
        $this->assertSame(
            $this->keys(1, 2, 3, 4, 5, 11),
            $this->category::whereNotDescendantOf($this->key(5))->orderBy('id')->pluck('id')->all(),
        );
    }

    public function testOrWhereDescendantOfUnionsSubtrees(): void
    {
        $this->assertSame(
            $this->keys(3, 4, 6, 7, 8, 9, 10),
            $this->category::whereDescendantOf($this->key(2))
                ->orWhereDescendantOf($this->key(5))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    public function testAncestorAndDescendantConstraintShortcuts(): void
    {
        $this->assertSame(
            $this->keys(1, 5, 7, 8),
            $this->category::whereAncestorOrSelf($this->key(8))->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 2, 5, 7),
            $this->category::whereAncestorOf($this->key(3))
                ->orWhereAncestorOf($this->key(8))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(7, 8),
            $this->category::whereDescendantOrSelf($this->key(7))->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 3, 4, 11),
            $this->category::whereDescendantOf($this->key(2))
                ->orWhereNotDescendantOf($this->key(1))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
        $this->assertSame(
            $this->keys(2, 3, 4, 11),
            $this->category::whereNodeBetween([2, 7])
                ->orWhereNodeBetween([21, 22])
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    #[DataProvider('partialNodeStateMethods')]
    public function testPersistedNodeStateCountsRequireLoadedBounds(string $method, array $columns): void
    {
        $node = $this->category::query()->select($columns)->findOrFail($this->key(5));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->category}] must have loaded bounds.",
        );

        $node->{$method}();
    }

    /**
     * Get node state methods with projections missing a required bound.
     */
    public static function partialNodeStateMethods(): array
    {
        return [
            'height without left bound' => ['getNodeHeight', ['id', '_rgt']],
            'height without right bound' => ['getNodeHeight', ['id', '_lft']],
            'descendant count without left bound' => ['getDescendantCount', ['id', '_rgt']],
            'descendant count without right bound' => ['getDescendantCount', ['id', '_lft']],
        ];
    }

    #[DataProvider('partialLeafProjections')]
    public function testLeafPredicateTreatsMissingBoundsAsFalse(array $attributes): void
    {
        $node = new $this->category;
        $node->setRawAttributes($attributes, true);
        $node->exists = true;

        $this->assertFalse($node->isLeaf());
    }

    /**
     * Get leaf projections missing a bound.
     */
    public static function partialLeafProjections(): array
    {
        return [
            'missing left bound' => [['id' => 3, '_rgt' => 4]],
            'missing right bound' => [['id' => 3, '_lft' => 3]],
            'missing left bound at the coercion edge' => [['id' => 99, '_rgt' => 1]],
        ];
    }

    public function testWithDepthWorks(): void
    {
        $nodes = $this->category::withDepth()->defaultOrder()->limit(4)->pluck('depth')->all();

        $this->assertEquals([0, 1, 2, 2], $nodes);
    }

    public function testWithDepthUsesStoredDepthColumnWhenAvailable(): void
    {
        DB::table('categories')->where('id', $this->key(3))->update(['depth' => 7]);

        $node = $this->category::withDepth('level')->findOrFail($this->key(3));

        $this->assertSame(7, $node['level']);
    }

    public function testWithDepthWithCustomKeyWorks(): void
    {
        $node = $this->category::whereIsRoot()->withDepth('level')->first();

        $this->assertSame(0, $node['level']);
    }

    public function testWithDepthWorksAlongWithDefaultKeys(): void
    {
        $node = $this->category::withDepth()->first();

        $this->assertTrue(isset($node->name));
    }

    public function testParentIdAttributeAccessorAppendsNode(): void
    {
        $node = new $this->category(['name' => 'lg', 'parent_id' => $this->key(5)]);
        $node->save();

        $this->assertEquals($this->key(5), $node->parent_id);
        $this->assertEquals($this->key(5), $node->getParentId());

        $node->parent_id = null;
        $node->save();

        $node->refreshNode();

        $this->assertNull($node->parent_id);
        $this->assertTrue($node->isRoot());
    }

    public function testParentIdAppendResolvesTheParentOnlyOnce(): void
    {
        $node = new $this->category(['name' => 'new phone']);
        $node->parent_id = $this->key(5);
        DB::flushQueryLog();

        $node->save();

        $this->assertSame(1, $this->countStructuralIdentityReloads());
        $this->assertSame($this->key(5), $node->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testParentIdMutatorReturnsTheNode(): void
    {
        $node = new $this->category(['name' => 'node']);

        $this->assertSame($node, $node->setAttribute('parent_id', $this->key(5)));
        $this->assertSame($node, $node->setAttribute('parent_id', $this->key(5)));
        $this->assertSame($node, $node->setAttribute('parent_id', null));
    }

    public function testFailsToSaveNodeUntilNotInserted(): void
    {
        $this->expectException(QueryException::class);

        $node = new $this->category;
        $node->save();
    }

    public function testNodeIsDeletedWithDescendants(): void
    {
        $node = $this->findCategory('mobile');
        $node->forceDelete();

        $this->assertTreeNotBroken();

        $nodes = $this->category::whereIn('id', $this->keys(5, 6, 7, 8, 9, 10))->count();
        $this->assertEquals(0, $nodes);

        $root = $this->category::root();
        $this->assertEquals(8, $root->getRgt());
    }

    public function testForceDeletingPartiallySelectedNodeRefreshesItsStructuralData(): void
    {
        $node = $this->category::query()
            ->select(['id', 'parent_id'])
            ->findOrFail($this->key(5));

        $node->forceDelete();

        $this->assertSame(0, $this->category::whereIn('id', $this->keys(5, 6, 7, 8, 9, 10))->count());
        $this->assertSame(8, $this->category::root()->getRgt());
        $this->assertTreeNotBroken();
    }

    public function testForceDeletingAStaleMissingModelDoesNotMutateTheTree(): void
    {
        $node = $this->category::findOrFail($this->key(3));
        $this->category::findOrFail($this->key(3))->forceDelete();

        $before = $this->persistedRows();

        $this->assertFalse($node->forceDelete());
        $this->assertSame($before, $this->persistedRows());
    }

    public function testDestroyingANodeWithItsDescendantDeletesTheSubtreeOnce(): void
    {
        $this->category::forceDestroy([$this->key(5), $this->key(7)]);

        $this->assertSame(0, $this->category::withTrashed()->whereIn('id', $this->keys(5, 6, 7, 8, 9, 10))->count());
        $this->assertSame(8, $this->category::root()->getRgt());
        $this->assertTreeNotBroken();
    }

    public function testNodeIsSoftDeleted(): void
    {
        CarbonImmutable::setTestNow('2025-07-03 12:00:00');

        $root = $this->category::root();

        $samsung = $this->findCategory('samsung');
        $samsung->delete();

        $this->assertTreeNotBroken();
        $this->assertNull($this->findCategory('galaxy'));

        CarbonImmutable::setTestNow('2025-07-03 12:00:01');

        $node = $this->findCategory('mobile');
        $node->delete();

        $nodes = $this->category::whereIn('id', $this->keys(5, 6, 7, 8, 9, 10))->count();
        $this->assertEquals(0, $nodes);

        $originalRgt = $root->getRgt();
        $root->refreshNode();

        $this->assertEquals($originalRgt, $root->getRgt());

        $node = $this->findCategory('mobile', true);
        $node->restore();

        $this->assertNull($this->findCategory('samsung'));
        $this->assertNotNull($this->findCategory('nokia'));
    }

    public function testSoftDeletingPartiallySelectedNodeRefreshesItsStructuralData(): void
    {
        $node = $this->category::query()
            ->select(['id', 'parent_id'])
            ->findOrFail($this->key(7));

        $node->delete();

        $this->assertNull($this->category::find($this->key(7)));
        $this->assertNull($this->category::find($this->key(8)));
        $this->assertNotNull($this->category::withTrashed()->find($this->key(7)));
        $this->assertNotNull($this->category::withTrashed()->find($this->key(8)));
        $this->assertTreeNotBroken();
    }

    public function testRestoringPartiallySelectedNodeRefreshesItsStructuralData(): void
    {
        $this->category::findOrFail($this->key(7))->delete();

        $node = $this->category::withTrashed()
            ->select(['id'])
            ->findOrFail($this->key(7));

        $node->restore();

        $this->assertNotNull($this->category::find($this->key(7)));
        $this->assertNotNull($this->category::find($this->key(8)));
        $this->assertTreeNotBroken();
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
        CarbonImmutable::setTestNow('2025-07-03 11:59:59');
        $this->findCategory('apple')->delete();

        CarbonImmutable::setTestNow('2025-07-03 12:00:00');
        $this->findCategory('notebooks')->delete();

        CarbonImmutable::setTestNow('2025-07-03 12:00:01');
        $this->findCategory('samsung')->delete();

        CarbonImmutable::setTestNow('2025-07-03 12:00:02');
        $this->findCategory('mobile')->delete();

        $nestedRestore = false;

        $this->category::restoring(function (Category $node) use (&$nestedRestore): void {
            if ($node->getKey() !== $this->key(5) || $nestedRestore) {
                return;
            }

            $nestedRestore = true;
            $this->category::withTrashed()->findOrFail($this->key(2))->restore();
        });

        $this->category::withTrashed()->findOrFail($this->key(5))->restore();

        $this->assertNull($this->findCategory('apple'));
        $this->assertNotNull($this->category::find($this->key(4)));
        $this->assertNotNull($this->findCategory('nokia'));
        $this->assertNull($this->findCategory('samsung'));
    }

    public function testRestoreIncludesDescendantsDeletedAtTheSameStoredTime(): void
    {
        CarbonImmutable::setTestNow('2025-07-03 12:00:00');

        $this->findCategory('samsung')->delete();
        $this->findCategory('mobile')->delete();

        $this->findCategory('mobile', true)->restore();

        $this->assertNotNull($this->findCategory('samsung'));
        $this->assertNotNull($this->findCategory('galaxy'));
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
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Node must be part of a tree.');

        $node = new $this->category(['name' => 'Node']);
        $parent = new $this->category(['name' => 'Parent']);

        $node->appendToNode($parent)->save();
    }

    public function testSiblings(): void
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->siblings()->pluck('id')->all();
        $next = $node->nextSiblings()->pluck('id')->all();
        $prev = $node->prevSiblings()->pluck('id')->all();

        $this->assertEqualsCanonicalizing($this->keys(6, 9, 10), $siblings);
        $this->assertEqualsCanonicalizing($this->keys(9, 10), $next);
        $this->assertEquals($this->keys(6), $prev);

        $siblings = $node->getSiblings()->pluck('id')->all();
        $next = $node->getNextSiblings()->pluck('id')->all();
        $prev = $node->getPrevSiblings()->pluck('id')->all();

        $this->assertEqualsCanonicalizing($this->keys(6, 9, 10), $siblings);
        $this->assertEqualsCanonicalizing($this->keys(9, 10), $next);
        $this->assertEquals($this->keys(6), $prev);

        $next = $node->getNextSibling();
        $prev = $node->getPrevSibling();

        $this->assertEquals($this->key(9), $next->id);
        $this->assertEquals($this->key(6), $prev->id);
    }

    public function testPredicatesRequirePersistedRowsAndUseLogicalRowIdentity(): void
    {
        $root = $this->category::findOrFail($this->key(1));
        $parent = $this->category::findOrFail($this->key(2));
        $child = $this->category::findOrFail($this->key(3));
        $sibling = $this->category::findOrFail($this->key(4));
        $unsaved = new $this->category([
            'parent_id' => $parent->getKey(),
            '_lft' => $child->getLft(),
            '_rgt' => $child->getRgt(),
        ]);

        $sameRow = new $this->category;
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

    public function testPartiallySelectedRowsWithoutKeysAreNotTheSameNode(): void
    {
        $first = $this->category::query()
            ->select(['_lft', '_rgt', 'parent_id', 'depth'])
            ->whereKey($this->key(3))
            ->firstOrFail();
        $second = $this->category::query()
            ->select(['_lft', '_rgt', 'parent_id', 'depth'])
            ->whereKey($this->key(4))
            ->firstOrFail();

        $this->assertFalse($first->isSelfOrDescendantOf($second));
        $this->assertFalse($first->isSelfOrAncestorOf($second));
    }

    #[DataProvider('partialAncestryPredicates')]
    public function testAncestryPredicatesTreatMissingBoundsAsFalse(
        array $descendantColumns,
        array $ancestorColumns,
    ): void {
        $descendant = $this->category::query()
            ->select($descendantColumns)
            ->findOrFail($this->key(7));
        $ancestor = $this->category::query()
            ->select($ancestorColumns)
            ->findOrFail($this->key(5));

        $this->assertFalse($descendant->isDescendantOf($ancestor));
        $this->assertFalse($descendant->isSelfOrDescendantOf($ancestor));
        $this->assertFalse($ancestor->isAncestorOf($descendant));
        $this->assertFalse($ancestor->isSelfOrAncestorOf($descendant));
    }

    /**
     * Get ancestry predicate cases missing a bound.
     */
    public static function partialAncestryPredicates(): array
    {
        return [
            'missing descendant left bound' => [
                ['id', '_rgt'],
                ['id', '_lft', '_rgt'],
            ],
            'missing ancestor left bound' => [
                ['id', '_lft', '_rgt'],
                ['id', '_rgt'],
            ],
            'missing ancestor right bound' => [
                ['id', '_lft', '_rgt'],
                ['id', '_lft'],
            ],
        ];
    }

    public function testSelfInclusiveAncestryPredicatesNeedOnlyPersistedIdentity(): void
    {
        $same = $this->category::query()->select(['id'])->findOrFail($this->key(3));

        $this->assertTrue($same->isSelfOrDescendantOf($same));
        $this->assertTrue($same->isSelfOrAncestorOf($same));
    }

    public function testIsSelfOrDescendantOf(): void
    {
        $mobile = $this->findCategory('mobile');
        $galaxy = $this->findCategory('galaxy');

        $this->assertTrue($galaxy->isSelfOrDescendantOf($mobile));
        $this->assertTrue($galaxy->isSelfOrDescendantOf($galaxy));
        $this->assertFalse($mobile->isSelfOrDescendantOf($galaxy));
    }

    public function testIsSelfOrAncestorOf(): void
    {
        $mobile = $this->findCategory('mobile');
        $galaxy = $this->findCategory('galaxy');

        $this->assertTrue($mobile->isSelfOrAncestorOf($galaxy));
        $this->assertTrue($mobile->isSelfOrAncestorOf($mobile));
        $this->assertFalse($galaxy->isSelfOrAncestorOf($mobile));
    }

    public function testStrictSiblingRelationsRequireASelectedParentKey(): void
    {
        $node = $this->category::query()
            ->select(['parent_id'])
            ->where('name', '=', 'apple')
            ->firstOrFail();

        $this->assertNull($node->getKey());
        $this->assertSame(
            $this->keys(3, 4),
            $node->siblingsAndSelf()->orderBy('id')->pluck('id')->all(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->category}] requires the [id] column.",
        );

        $node->siblings()->get();
    }

    #[DataProvider('strictSiblingEagerParentIds')]
    public function testStrictSiblingEagerLoadingRequiresTheRelatedKey(array $parentIds): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(...$parentIds))->get();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation eager load for [{$this->category}] requires the [id] column.",
        );

        $nodes->load([
            'siblings' => fn (SiblingsRelation $query): SiblingsRelation => $query->select(['parent_id', 'name']),
        ]);
    }

    /**
     * Get parent keys for strict sibling eager loading.
     */
    public static function strictSiblingEagerParentIds(): array
    {
        return [
            'single parent' => [[3]],
            'multiple parents' => [[3, 6]],
        ];
    }

    #[DataProvider('siblingRelations')]
    public function testSiblingEagerLoadingRequiresTheRelatedParentColumn(string $relation): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(1, 3))->get();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation eager load for [{$this->category}] requires the [parent_id] column.",
        );

        $nodes->load([
            $relation => fn (SiblingsRelation $query): SiblingsRelation => $query->select(['id', 'name']),
        ]);
    }

    /**
     * Get the sibling relation names.
     */
    public static function siblingRelations(): array
    {
        return [
            'siblings' => ['siblings'],
            'siblings and self' => ['siblingsAndSelf'],
        ];
    }

    public function testSiblingRelationsRequireSelectedParentage(): void
    {
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(7));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->category}] requires the [parent_id] column.",
        );

        $node->siblings()->get();
    }

    #[DataProvider('eagerRelationParents')]
    public function testPersistedPartialEagerParentsAreRejected(
        string $relation,
        int $parentId,
        string $requiredColumn,
        bool $partialFirst,
    ): void {
        $partial = $this->category::query()->select(['id'])->findOrFail($this->key($parentId));
        $complete = $this->category::findOrFail($this->key($parentId));
        $models = $partialFirst
            ? [$partial, $complete]
            : [$complete, $partial];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->category}] requires the [{$requiredColumn}] column.",
        );

        $complete->newCollection($models)->load($relation);
    }

    /**
     * Get eager relation parents mixing partial and complete projections.
     */
    public static function eagerRelationParents(): array
    {
        return [
            'ancestors, partial first' => ['ancestors', 3, '_lft', true],
            'ancestors, complete first' => ['ancestors', 3, '_lft', false],
            'descendants, partial first' => ['descendants', 2, '_lft', true],
            'descendants, complete first' => ['descendants', 2, '_lft', false],
            'siblings, partial first' => ['siblings', 3, 'parent_id', true],
            'siblings, complete first' => ['siblings', 3, 'parent_id', false],
            'siblings and self, partial first' => ['siblingsAndSelf', 3, 'parent_id', true],
            'siblings and self, complete first' => ['siblingsAndSelf', 3, 'parent_id', false],
        ];
    }

    #[DataProvider('incompleteRelationParents')]
    public function testIncompleteEagerParentsAreRejected(string $relation, string $requiredColumn): void
    {
        $nodes = $this->category::query()
            ->select(['id'])
            ->whereIn('id', $this->keys(3, 7))
            ->get();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->category}] requires the [{$requiredColumn}] column.",
        );

        $nodes->load($relation);
    }

    #[DataProvider('incompleteRelationParents')]
    public function testIncompleteLazyParentsAreRejected(string $relation, string $requiredColumn): void
    {
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(7));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->category}] requires the [{$requiredColumn}] column.",
        );

        $node->{$relation}()->toSql();
    }

    #[DataProvider('relationNames')]
    public function testUnsavedRelationParentsRemainEmpty(string $relation): void
    {
        $node = new $this->category;
        DB::flushQueryLog();

        $this->assertTrue($node->getRelationValue($relation)->isEmpty());
        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * Get the nested set relation names.
     */
    public static function relationNames(): array
    {
        return array_map(
            static fn (array $case): array => [$case[0]],
            static::incompleteRelationParents(),
        );
    }

    public function testUnsavedDuplicateDoesNotSuppressACompleteEagerParent(): void
    {
        $complete = $this->category::findOrFail($this->key(3));
        $unsaved = new $this->category;
        $unsaved->setRawAttributes($complete->getAttributes(), true);

        $models = new Collection([$unsaved, $complete]);
        $models->load('ancestors');

        $this->assertTrue($unsaved->ancestors->isEmpty());
        $this->assertSame($this->keys(1, 2), $complete->ancestors->pluck('id')->all());
    }

    #[DataProvider('incompleteRelationParents')]
    public function testDestructiveRelationQueriesRejectIncompletePersistedParents(
        string $relation,
        string $requiredColumn,
    ): void {
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(7));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->category}] requires the [{$requiredColumn}] column.",
        );

        $node->{$relation}()->delete();
    }

    /**
     * Get each relation with the parent column it requires.
     */
    public static function incompleteRelationParents(): array
    {
        return [
            'ancestors' => ['ancestors', '_lft'],
            'descendants' => ['descendants', '_lft'],
            'siblings' => ['siblings', 'parent_id'],
            'siblings and self' => ['siblingsAndSelf', 'parent_id'],
        ];
    }

    public function testFetchesReversed(): void
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->value('id');

        $this->assertEquals($this->key(7), $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder(): void
    {
        $tree = $this->category::whereBetween('_lft', [8, 17])->defaultOrder()->get()->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
        $this->assertSame($this->keys(6, 7, 9, 10), $root->children->modelKeys());
    }

    public function testToTreeKeepsEmptyChildrenRelationOnLeaves(): void
    {
        $tree = $this->category::defaultOrder()->get()->toTree();
        $notebooks = $tree->first()->children->firstWhere('name', 'notebooks');
        $apple = $notebooks->children->firstWhere('name', 'apple');

        $this->assertTrue($apple->relationLoaded('children'));
        $this->assertCount(0, $apple->children);
    }

    public function testToTreeBuildsWithCustomOrder(): void
    {
        $tree = $this->category::whereBetween('_lft', [8, 17])
            ->orderBy('name')
            ->get()
            ->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
        $this->assertSame($this->keys(10, 6, 7, 9), $root->children->modelKeys());
        $this->assertNotSame($root, $root->children->first()->parent);
        $this->assertSame($root->getAttributes(), $root->children->first()->parent->getAttributes());
        $this->assertSame([], $root->children->first()->parent->getRelations());
    }

    public function testLinkNodesKeepsCollectionOrderForChildren(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(5, 6, 9, 10))->orderBy('name')->get();

        $nodes->linkNodes();

        $mobile = $nodes->find($this->key(5));
        $lenovo = $nodes->find($this->key(10));

        $this->assertSame(['lenovo', 'nokia', 'sony'], $mobile->children->pluck('name')->all());
        $this->assertSame($mobile->getAttributes(), $lenovo->parent->getAttributes());
    }

    #[DataProvider('treeBuildingProjectionRequirements')]
    public function testTreeBuildingRequiresStructuralProjection(
        string $method,
        array $columns,
        string $requiredColumn,
    ): void {
        $nodes = $this->category::defaultOrder()->get($columns);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(sprintf(
            'Nested set tree building for [%s] requires the [%s] column in the projection.',
            $this->category,
            $requiredColumn,
        ));

        $nodes->{$method}();
    }

    /**
     * Get tree building methods with projections missing a required column.
     */
    public static function treeBuildingProjectionRequirements(): array
    {
        return [
            'link nodes without key' => ['linkNodes', ['_lft', 'parent_id', 'name'], 'id'],
            'tree without key' => ['toTree', ['_lft', 'parent_id', 'name'], 'id'],
            'flat tree without key' => ['toFlatTree', ['_lft', 'parent_id', 'name'], 'id'],
            'link nodes without parent' => ['linkNodes', ['id', '_lft', 'name'], 'parent_id'],
            'tree without parent' => ['toTree', ['id', '_lft', 'name'], 'parent_id'],
            'flat tree without parent' => ['toFlatTree', ['id', '_lft', 'name'], 'parent_id'],
        ];
    }

    #[DataProvider('treeBuildingMethods')]
    public function testInferredRootTreeBuildingRequiresTheLeftBound(string $method): void
    {
        $nodes = $this->category::query()
            ->whereBetween('_lft', [2, 7])
            ->defaultOrder()
            ->get(['id', 'parent_id', 'name']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set tree building for [{$this->category}] requires the [_lft] column in the projection.",
        );

        $nodes->{$method}();
    }

    #[DataProvider('treeBuildingMethods')]
    public function testTreeBuildingRequiresAKeyOnTheSuppliedRoot(string $method): void
    {
        $root = $this->category::query()
            ->select(['_lft', '_rgt', 'name'])
            ->findOrFail($this->key(2));
        $nodes = $this->category::defaultOrder()->get();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set tree building for [{$this->category}] requires the [id] column on the supplied root.",
        );

        $nodes->{$method}($root);
    }

    #[DataProvider('treeBuildingMethods')]
    public function testTreeBuildingRejectsRootModelsWithoutNestedSet(string $method): void
    {
        $root = new class extends Model {
        };
        $nodes = $this->category::defaultOrder()->get();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf(
            'Model [%s] must be node.',
            $root::class,
        ));

        $nodes->{$method}($root);
    }

    /**
     * Get the collection tree building methods.
     */
    public static function treeBuildingMethods(): array
    {
        return [
            'tree' => ['toTree'],
            'flat tree' => ['toFlatTree'],
        ];
    }

    public function testExplicitRootTreeBuildingDoesNotRequireTheLeftBound(): void
    {
        $root = $this->category::query()->select(['id'])->findOrFail($this->key(2));
        $nodes = $this->category::query()
            ->whereBetween('_lft', [2, 7])
            ->defaultOrder()
            ->get(['id', 'parent_id', 'name']);

        $this->assertSame($this->keys(3, 4), $nodes->toTree($root)->modelKeys());
        $this->assertSame($this->keys(3, 4), $nodes->toFlatTree($root)->modelKeys());
    }

    public function testToTreeWithSpecifiedRoot(): void
    {
        $node = $this->findCategory('mobile');
        $nodes = $this->category::whereBetween('_lft', [8, 17])->get();

        $tree1 = Collection::make($nodes)->toTree($this->key(5));
        $tree2 = Collection::make($nodes)->toTree($node);

        $this->assertEquals(4, $tree1->count());
        $this->assertEquals(4, $tree2->count());
    }

    public function testLinkNodesClearsStaleRelationsAndUsesSharedRelationFreeParents(): void
    {
        $nodes = $this->category::defaultOrder()->get();
        $leaf = $nodes->find($this->key(8));

        $leaf->setRelation('parent', new $this->category(['name' => 'stale']));
        $leaf->setRelation('children', new Collection([new $this->category]));

        $nodes->linkNodes();

        $siblings = $nodes->whereIn('id', $this->keys(6, 7, 9, 10))->values();
        $parent = $siblings->first()->parent;

        $this->assertTrue($leaf->relationLoaded('children'));
        $this->assertTrue($leaf->children->isEmpty());
        $this->assertSame($this->key(7), $leaf->parent->getKey());
        $this->assertSame([], $leaf->parent->getRelations());

        foreach ($siblings as $sibling) {
            $this->assertSame($parent, $sibling->parent);
        }
    }

    public function testLinkNodesKeepsLoadedParentsOutsideTheCollection(): void
    {
        $nodes = $this->category::with('parent')
            ->whereDescendantOf($this->key(5))
            ->defaultOrder()
            ->get();

        $nodes->linkNodes();

        $nokia = $nodes->find($this->key(6));

        $this->assertTrue($nokia->relationLoaded('parent'));
        $this->assertSame($this->key(5), $nokia->parent->getKey());
    }

    public function testLinkedTreeIsJsonSerializable(): void
    {
        $tree = $this->category::defaultOrder()->get()->toTree();
        $decoded = json_decode($tree->toJson(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->key(1), $decoded[0]['id']);
        $this->assertSame($this->key(1), $decoded[0]['children'][0]['parent']['id']);
        $this->assertArrayNotHasKey('children', $decoded[0]['children'][0]['parent']);
    }

    public function testToFlatTreeHandlesDeepTreeIteratively(): void
    {
        $nodes = [];

        for ($id = 1; $id <= 2000; ++$id) {
            $nodes[] = $this->makeCollectionNode(
                $this->key($id),
                $id,
                4001 - $id,
                $id === 1 ? null : $this->key($id - 1),
            );
        }

        $flat = (new Collection($nodes))->toFlatTree();

        $this->assertSame($this->keys(...range(1, 2000)), $flat->modelKeys());
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes(): void
    {
        $tree = $this->category::withoutRoot()->defaultOrder()->get()->toTree();

        $this->assertSame($this->keys(2, 5), $tree->modelKeys());
    }

    public function testToTreeBuildsWithRootItemIdProvided(): void
    {
        $tree = $this->category::whereBetween('_lft', [8, 17])->defaultOrder()->get()->toTree($this->key(5));

        $this->assertEquals(4, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function testRetrievesNextNode(): void
    {
        $node = $this->findCategory('apple');
        $next = $node->getNextNode();

        $this->assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode(): void
    {
        $node = $this->findCategory('apple');
        $next = $node->getPrevNode();

        $this->assertEquals('notebooks', $next->name);
    }

    public function testGetNextNodeCrossesSubtreeBoundary(): void
    {
        $this->assertSame('sony', $this->findCategory('galaxy')->getNextNode()->name);
    }

    public function testGetPrevNodeCrossesSubtreeBoundary(): void
    {
        $this->assertSame('galaxy', $this->findCategory('sony')->getPrevNode()->name);
    }

    public function testWhereIsBeforeAndAfterById(): void
    {
        $before = $this->category::whereIsBefore($this->key(4))
            ->defaultOrder()
            ->pluck('name')
            ->all();
        $after = $this->category::whereIsAfter($this->key(4))
            ->defaultOrder()
            ->pluck('name')
            ->all();

        $this->assertSame(['store', 'notebooks', 'apple'], $before);
        $this->assertSame(['mobile', 'nokia', 'samsung', 'galaxy', 'sony', 'lenovo', 'store_2'], $after);
    }

    public function testStructuralCoordinateLookupsRespectTheOuterSoftDeleteMode(): void
    {
        DB::table('categories')
            ->whereIn('id', $this->keys(3, 5))
            ->update(['deleted_at' => CarbonImmutable::now()]);

        $this->assertSame(
            $this->keys(1, 2, 3, 4),
            $this->category::withTrashed()->whereIsBefore($this->key(5))->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(3),
            $this->category::onlyTrashed()->whereIsBefore($this->key(5))->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 5, 7),
            $this->category::withTrashed()->whereAncestorOf($this->key(8))->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(5),
            $this->category::onlyTrashed()->whereAncestorOf($this->key(8))->pluck('id')->all(),
        );
    }

    public function testMultipleAppendageWorks(): void
    {
        $parent = $this->findCategory('mobile');
        $child = new $this->category(['name' => 'test']);
        $subchild = new $this->category(['name' => 'sub']);
        $sibling = new $this->category(['name' => 'test2']);

        $parent->appendNode($child);
        $child->appendNode($subchild);
        $parent->appendNode($sibling);

        $this->assertSame($parent->getKey(), $child->getParentId());
        $this->assertSame($child->getKey(), $subchild->getParentId());
        $this->assertSame($parent->getKey(), $sibling->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testDefaultCategoryIsSavedAsRoot(): void
    {
        $node = new $this->category(['name' => 'test']);
        $node->save();

        $this->assertEquals(23, $node->_lft);
        $this->assertTreeNotBroken();

        $this->assertTrue($node->isRoot());
    }

    public function testExistingCategorySavedAsRoot(): void
    {
        $node = $this->findCategory('samsung');
        $node->saveAsRoot();

        $this->assertSame(0, $this->category::findOrFail($node->getKey())->getDepth());
        $this->assertSame(1, $this->findCategory('galaxy')->getDepth());
        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());
    }

    public function testSavingPartiallySelectedChildAsRootHydratesItsParentage(): void
    {
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(7));

        $this->assertTrue($node->saveAsRoot());

        $node = $this->category::findOrFail($this->key(7));

        $this->assertNull($node->getParentId());
        $this->assertSame(0, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testSavingPartiallySelectedRootAsRootDoesNotReorderIt(): void
    {
        $before = $this->category::findOrFail($this->key(1))->getBounds();
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(1));

        $this->assertTrue($node->saveAsRoot());

        $this->assertSame($before, $this->category::findOrFail($this->key(1))->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testSavingNewExplicitRootAssignsValidBounds(): void
    {
        $node = new $this->category(['name' => 'explicit root']);
        $node->parent_id = null;

        $this->assertTrue($node->saveAsRoot());

        $this->assertGreaterThan(0, $node->getLft());
        $this->assertSame($node->getLft() + 1, $node->getRgt());
        $this->assertSame(0, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testMakeRootAlwaysRepositionsAnExistingRoot(): void
    {
        $node = $this->category::findOrFail($this->key(1));

        $this->assertTrue($node->makeRoot()->save());

        $this->assertSame([3, 22], $node->getBounds());
        $this->assertNull($node->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testAssigningNullParentToPartialChildDoesNotLookLikeAnExistingRoot(): void
    {
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(7));

        $node->parent_id = null;
        $node->save();

        $this->assertNull($this->category::findOrFail($this->key(7))->getParentId());
        $this->assertSame(0, $this->category::findOrFail($this->key(7))->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testMultipleRootNodesAreSiblings(): void
    {
        $store = $this->findCategory('store');
        $store2 = $this->findCategory('store_2');

        $this->assertTrue($store->isSiblingOf($store2));
        $this->assertTrue($store2->isSiblingOf($store));
    }

    public function testMultipleRootNodesAreNotChildren(): void
    {
        $store = $this->findCategory('store');
        $store2 = $this->findCategory('store_2');

        $this->assertFalse($store->isChildOf($store2));
        $this->assertFalse($store2->isChildOf($store));
    }

    public function testMultipleRootNodesInToTree(): void
    {
        $tree = $this->category::defaultOrder()->get()->toTree();

        $this->assertCount(2, $tree);
        $this->assertSame('store', $tree->first()->name);
        $this->assertSame('store_2', $tree->last()->name);
    }

    public function testMultipleRootNodesInToFlatTree(): void
    {
        $tree = $this->category::defaultOrder()->get()->toFlatTree();

        $this->assertCount(11, $tree);
        $this->assertSame('store', $tree->first()->name);
        $this->assertSame('store_2', $tree->last()->name);
    }

    public function testNewRootNodeIsSiblingOfExisting(): void
    {
        $node = new $this->category(['name' => 'store_3']);
        $node->save();

        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());

        $store = $this->findCategory('store');

        $this->assertTrue($node->isSiblingOf($store));
        $this->assertTrue($store->isSiblingOf($node));
    }

    public function testSetParentIdToNullKeepsRoot(): void
    {
        $store = $this->findCategory('store');
        $store->parent_id = null;

        $this->assertTrue($store->isRoot());
        $this->assertFalse($store->isDirty());
        $this->assertTreeNotBroken();
    }

    public function testChildIsNotSiblingOfRoot(): void
    {
        $store = $this->findCategory('store');
        $notebooks = $this->findCategory('notebooks');

        $this->assertFalse($store->isSiblingOf($notebooks));
        $this->assertFalse($notebooks->isSiblingOf($store));
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

    #[DataProvider('nonPositiveMoveAmounts')]
    public function testNonPositiveSiblingMovementIsANoOp(string $method, int $amount): void
    {
        $node = $this->category::findOrFail($this->key(9));
        DB::flushQueryLog();

        $this->assertFalse($node->{$method}($amount));
        $this->assertSame([], DB::getQueryLog());
    }

    /**
     * Get sibling movements with non-positive amounts.
     */
    public static function nonPositiveMoveAmounts(): array
    {
        return [
            'up zero' => ['up', 0],
            'up negative' => ['up', -1],
            'down zero' => ['down', 0],
            'down negative' => ['down', -1],
        ];
    }

    public function testCountsTreeErrors(): void
    {
        $this->assertTreeNotBroken();

        $this->category::where('id', '=', $this->key(5))->update(['_lft' => 14]);
        $this->category::where('id', '=', $this->key(8))->update(['parent_id' => $this->key(2)]);
        $this->category::where('id', '=', $this->key(11))->update(['_lft' => 20]);
        $this->category::where('id', '=', $this->key(4))->update(['parent_id' => $this->key(24)]);

        $this->assertSame([
            'invalid_intervals' => 1,
            'duplicate_endpoints' => 2,
            'missing_endpoints' => 0,
            'crossing_intervals' => 0,
            'missing_parent' => 1,
            'wrong_parent' => 3,
            'wrong_depth' => 1,
        ], $this->category::countErrors());
    }

    public function testCountsWrongParentErrors(): void
    {
        $this->category::where('id', '=', $this->key(8))->update(['parent_id' => $this->key(5)]);

        $this->assertSame([
            'invalid_intervals' => 0,
            'duplicate_endpoints' => 0,
            'missing_endpoints' => 0,
            'crossing_intervals' => 0,
            'missing_parent' => 0,
            'wrong_parent' => 1,
            'wrong_depth' => 1,
        ], $this->category::countErrors());
    }

    public function testCountsNestedWrongParentErrors(): void
    {
        $this->category::where('id', '=', $this->key(8))->update(['parent_id' => $this->key(1)]);

        $errors = $this->category::countErrors();

        // A misparented node counts once, however many nodes lie between it and its stored parent.
        $this->assertSame(1, $errors['wrong_parent']);
        $this->assertSame(1, $errors['wrong_depth']);

        // With a matching depth, the node's endpoints disagree with the intervals around them.
        $this->category::where('id', '=', $this->key(8))->update(['depth' => 1]);

        $errors = $this->category::countErrors();

        $this->assertSame(0, $errors['wrong_parent']);
        $this->assertSame(0, $errors['wrong_depth']);
        $this->assertSame(2, $errors['crossing_intervals']);
    }

    public function testIsBrokenDetectsWrongParentErrors(): void
    {
        $this->category::where('id', '=', $this->key(8))->update(['parent_id' => $this->key(5)]);

        $this->assertTrue($this->category::isBroken());
    }

    public function testIsBrokenDetectsDuplicateErrors(): void
    {
        $this->category::where('id', '=', $this->key(11))->update(['_lft' => 3]);

        DB::flushQueryLog();

        $this->assertTrue($this->category::isBroken());

        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('join', strtolower($query['query']));
        }
    }

    public function testIsBrokenShortCircuitsOnOddness(): void
    {
        $this->category::where('id', '=', $this->key(5))->update([
            '_lft' => 14,
            '_rgt' => 13,
        ]);

        DB::flushQueryLog();

        $this->assertTrue($this->category::isBroken());

        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('_lft', $queries[0]['query']);
        $this->assertStringNotContainsString('join', strtolower($queries[0]['query']));
    }

    public function testCountsInvalidIntervals(): void
    {
        $this->category::whereKey($this->key(3))->update(['_lft' => 0]);

        $this->assertSame(1, $this->category::countErrors()['invalid_intervals']);
        $this->assertTrue($this->category::isBroken());
    }

    public function testCountsDuplicateEndpointsAndSkipsAmbiguousCrossingAnalysis(): void
    {
        $this->category::whereKey($this->key(4))->update(['_lft' => 3]);

        $errors = $this->category::countErrors();

        $this->assertSame(1, $errors['duplicate_endpoints']);
        $this->assertSame(0, $errors['crossing_intervals']);
    }

    public function testCountsMissingEndpointRanges(): void
    {
        DB::table('categories')->where('id', $this->key(4))->delete();

        $this->assertSame(1, $this->category::countErrors()['missing_endpoints']);
    }

    public function testCountsCrossingIntervalsWithUniqueContiguousEndpoints(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => $this->key(1), 'name' => 'a', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'b', '_lft' => 2, '_rgt' => 5, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(3), 'name' => 'c', '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'depth' => 0],
        ]);

        $errors = $this->category::countErrors();

        $this->assertSame(0, $errors['invalid_intervals']);
        $this->assertSame(0, $errors['duplicate_endpoints']);
        $this->assertSame(0, $errors['missing_endpoints']);
        $this->assertGreaterThan(0, $errors['crossing_intervals']);
    }

    public function testCreatesNode(): void
    {
        $node = $this->category::create(['name' => 'test']);

        $this->assertEquals(23, $node->getLft());
    }

    public function testCreatesViaRelationship(): void
    {
        $node = $this->findCategory('apple');

        $child = $node->children()->create(['name' => 'test']);

        $this->assertSame($node->getKey(), $child->getParentId());
        $this->assertSame(3, $child->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testCreatesTree(): void
    {
        $node = $this->category::create(
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

        $this->assertSame(['test2', 'test3'], $node->children()->defaultOrder()->pluck('name')->all());
    }

    public function testRecursiveCreateUsesOnePreparationReadPerParticipantAndOneFinalRootRefresh(): void
    {
        DB::flushQueryLog();

        $this->category::create([
            'name' => 'test',
            'children' => [
                ['name' => 'one'],
                ['name' => 'two'],
                ['name' => 'three'],
                ['name' => 'four'],
            ],
        ]);

        $this->assertSame(9, $this->countStructuralIdentityReloads());
        $this->assertTreeNotBroken();
    }

    public function testCreateReturnsBoundsWidenedByTheLastChildsDescendants(): void
    {
        $node = $this->category::create([
            'name' => 'test',
            'children' => [
                ['name' => 'first'],
                ['name' => 'last', 'children' => [['name' => 'grandchild']]],
            ],
        ]);

        $this->assertSame([23, 30], $node->getBounds());
        $this->assertSame($node->getBounds(), $this->findCategory('test')->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testDescendantsOfNonExistingNode(): void
    {
        $node = new $this->category;

        $this->assertTrue($node->getDescendants()->isEmpty());
    }

    #[DataProvider('nodeObjectPositionalQueries')]
    public function testNodeObjectPositionalQueriesRequireLoadedBounds(string $method): void
    {
        $node = $this->category::query()->select(['id'])->findOrFail($this->key(7));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->category}] must have loaded bounds.",
        );

        $this->category::query()->{$method}($node)->get();
    }

    /**
     * Get the positional query methods that accept a node.
     */
    public static function nodeObjectPositionalQueries(): array
    {
        return [
            'ancestors' => ['whereAncestorOf'],
            'descendants' => ['whereDescendantOf'],
            'before' => ['whereIsBefore'],
            'after' => ['whereIsAfter'],
        ];
    }

    #[DataProvider('nodeObjectPositionalQueries')]
    public function testNodeObjectPositionalQueriesRejectModelsWithoutNestedSet(string $method): void
    {
        $node = new class extends Model {
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf(
            'Model [%s] must be node.',
            $node::class,
        ));

        $this->category::query()->{$method}($node)->get();
    }

    #[DataProvider('directNodePositionQueries')]
    public function testDirectNodePositionQueriesRequireLoadedBounds(string $method): void
    {
        $node = $this->category::query()->select(['id', 'parent_id'])->findOrFail($this->key(7));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->category}] must have loaded bounds.",
        );

        $node->{$method}()->get();
    }

    /**
     * Get the node's own positional query methods.
     */
    public static function directNodePositionQueries(): array
    {
        return [
            'next nodes' => ['nextNodes'],
            'previous nodes' => ['prevNodes'],
            'next siblings' => ['nextSiblings'],
            'previous siblings' => ['prevSiblings'],
        ];
    }

    public function testDirectSiblingQueriesRequireLoadedParentage(): void
    {
        $node = $this->category::query()
            ->select(['id', '_lft', '_rgt', 'depth'])
            ->findOrFail($this->key(7));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->category}] must have a loaded parent.",
        );

        $node->nextSiblings()->get();
    }

    public function testNodeObjectPositionalQueriesRejectAnotherStore(): void
    {
        $node = $this->category::findOrFail($this->key(7));
        $node->setTable('other_categories');
        $connection = DB::getDefaultConnection();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->category}] uses connection [{$connection}] and table [other_categories], but query model [{$this->category}] uses connection [{$connection}] and table [categories].",
        );

        $this->category::query()->whereDescendantOf($node)->get();
    }

    public function testWhereDescendantsOf(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessageIs(
            "No query results for model [{$this->category}] {$this->key(124)}",
        );

        $this->category::whereDescendantOf($this->key(124))->get();
    }

    public function testAncestorsByNode(): void
    {
        $category = $this->findCategory('apple');
        $ancestors = $this->category::whereAncestorOf($category)->pluck('id')->all();

        $this->assertEqualsCanonicalizing($this->keys(1, 2), $ancestors);
    }

    public function testAncestorsByNodeWithoutSelectedKey(): void
    {
        $category = $this->category::query()
            ->select(['_lft', '_rgt'])
            ->where('name', '=', 'apple')
            ->firstOrFail();

        $this->assertNull($category->getKey());
        $this->assertSame(
            $this->keys(1, 2),
            $this->category::whereAncestorOf($category)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(1, 2, 3),
            $this->category::whereAncestorOf($category, true)->orderBy('id')->pluck('id')->all(),
        );

        $root = $this->category::query()
            ->select(['_lft', '_rgt'])
            ->findOrFail($this->key(1));

        $this->assertTrue($this->category::whereAncestorOf($root)->get()->isEmpty());
        $this->assertSame($this->keys(1), $this->category::whereAncestorOf($root, true)->pluck('id')->all());
    }

    #[DataProvider('eagerRelatedProjections')]
    public function testEagerMatchingUsesOnlyTruthfulRelatedProjections(
        string $relation,
        array $parentIds,
        array $columns,
        array $expected,
        ?string $requiredColumn,
    ): void {
        $categories = $this->category::query()->whereIn('id', $this->keys(...$parentIds))->get();

        if ($requiredColumn !== null) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageIs(
                "Nested set relation eager load for [{$this->category}] requires the [{$requiredColumn}] column.",
            );
        }

        $categories->load([
            $relation => fn (BaseRelation $query): BaseRelation => $query->select($columns),
        ]);

        foreach ($expected as $parentId => $relatedIds) {
            $this->assertSame(
                $this->keys(...$relatedIds),
                $categories->find($this->key($parentId))
                    ->getRelation($relation)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all(),
            );
        }
    }

    /**
     * Get eager related projections with their expected matches or missing column.
     */
    public static function eagerRelatedProjections(): array
    {
        return [
            'ancestor without left bound' => [
                'ancestors',
                [3],
                ['id', '_rgt'],
                [],
                '_lft',
            ],
            'ancestors without right bound across multiple parents' => [
                'ancestors',
                [3, 8],
                ['id', '_lft'],
                [],
                '_rgt',
            ],
            'descendants without left bound across multiple parents' => [
                'descendants',
                [2, 5],
                ['id', '_rgt'],
                [],
                '_lft',
            ],
            'descendants need no related right bound' => [
                'descendants',
                [2, 5],
                ['id', '_lft'],
                [2 => [3, 4], 5 => [6, 7, 8, 9, 10]],
                null,
            ],
        ];
    }

    public function testDescendantsByNode(): void
    {
        $category = $this->findCategory('notebooks');
        $res = $this->category::whereDescendantOf($category)->pluck('id')->all();

        $this->assertEqualsCanonicalizing($this->keys(3, 4), $res);
    }

    public function testMultipleDeletionsDoNotBreakTree(): void
    {
        $category = $this->findCategory('mobile');

        foreach ($category->children()->defaultOrder()->take(2)->get() as $child) {
            $child->forceDelete();
        }

        $this->assertSame(0, $this->category::whereIn('id', $this->keys(6, 7, 8))->count());
        $this->assertSame(2, $this->category::whereIn('id', $this->keys(9, 10))->count());
        $this->assertTreeNotBroken();
    }

    public function testTreeIsFixed(): void
    {
        $this->category::where('id', '=', $this->key(5))->update(['_lft' => 14]);
        $this->category::where('id', '=', $this->key(8))->update(['parent_id' => $this->key(2)]);
        $this->category::where('id', '=', $this->key(11))->update(['_lft' => 20]);
        $this->category::where('id', '=', $this->key(2))->update(['parent_id' => $this->key(24)]);

        $fixed = $this->category::fixTree();

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = $this->category::find($this->key(8));

        $this->assertEquals($this->key(2), $node->getParentId());

        $node = $this->category::find($this->key(2));

        $this->assertEquals(null, $node->getParentId());
    }

    public function testFixTreeRepairsBranchingParentBuckets(): void
    {
        $this->category::query()->update([
            '_lft' => 0,
            '_rgt' => 0,
            'depth' => 0,
        ]);

        $fixed = $this->category::fixTree();

        $this->assertSame(11, $fixed);
        $this->assertTreeNotBroken();

        $galaxy = $this->category::find($this->key(8));

        $this->assertSame(3, $galaxy->getDepth());
        $this->assertSame($this->keys(1, 5, 7), $galaxy->getAncestors()->pluck('id')->all());
    }

    public function testFixTreePromotesOrphanedBranchesToRoots(): void
    {
        $this->category::whereKey($this->key(2))->update(['parent_id' => $this->key(24)]);

        $this->category::fixTree();

        $node = $this->category::find($this->key(2));

        $this->assertNull($node->getParentId());
        $this->assertSame(0, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testFixTreePersistsExpandedRootBounds(): void
    {
        $root = $this->category::findOrFail($this->key(1));

        $this->category::whereKey($this->key(11))->update(['parent_id' => $this->key(1)]);
        $this->category::fixTree();

        $root->refreshNode();

        $this->assertSame(22, $root->getRgt());
        $this->assertTreeNotBroken();
    }

    public function testFixTreeSelectsExplicitObserverColumns(): void
    {
        $retrieved = [];
        $saved = [];

        $this->category::retrieved(function (Category $model) use (&$retrieved): void {
            $retrieved[] = $model->getKey();
        });
        $this->category::saving(function (Category $model) use (&$saved): void {
            $saved[] = [$model->getKey(), $model->name];
        });

        // Repair hydrates only the nodes it saves.
        $this->assertSame(0, $this->category::fixTree(extraColumns: ['name']));
        $this->assertSame([], $retrieved);
        $this->assertSame([], $saved);

        $this->category::whereKey($this->key(8))->update(['_lft' => 11]);

        $this->assertSame(1, $this->category::fixTree(extraColumns: ['name']));
        $this->assertSame([$this->key(8)], $retrieved);
        $this->assertSame([[$this->key(8), 'galaxy']], $saved);
        $this->assertTreeNotBroken();
    }

    public function testFixTreeVetoRollsBackEarlierRepairWrites(): void
    {
        $this->category::whereKey($this->key(2))->update(['parent_id' => null]);

        $before = DB::table('categories')
            ->orderBy('id')
            ->get(['id', '_lft', '_rgt', 'parent_id', 'depth'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $saves = 0;
        $vetoedKey = null;

        $this->category::saving(function (Category $model) use (&$saves, &$vetoedKey): ?bool {
            if (++$saves !== 2) {
                return null;
            }

            $vetoedKey = $model->getKey();

            return false;
        });

        try {
            DB::transaction(fn (): int => $this->category::fixTree());
            $this->fail('Expected the repair veto to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf(
                    'Saving nested set node [%s] with key [%s] during repair was vetoed.',
                    $this->category,
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

    public function testFixTreeHandlesDeepParentChainsIteratively(): void
    {
        DB::table('categories')->delete();

        $rows = [];
        $count = 200;

        for ($id = 1; $id <= $count; ++$id) {
            $rows[] = [
                'id' => $this->key($id),
                'name' => 'node ' . $id,
                '_lft' => 0,
                '_rgt' => 0,
                'parent_id' => $id === 1 ? null : $this->key($id - 1),
                'depth' => 0,
            ];
        }

        DB::table('categories')->insert($rows);

        $fixed = $this->category::fixTree();

        $root = $this->category::find($this->key(1));
        $leaf = $this->category::find($this->key($count));

        $this->assertSame($count, $fixed);
        $this->assertSame([1, $count * 2], $root->getBounds());
        $this->assertSame([$count, $count + 1], $leaf->getBounds());
        $this->assertSame($count - 1, $leaf->getDepth());
        $this->assertTreeNotBroken();
    }

    #[DataProvider('invalidSubtreeParents')]
    public function testFixSubtreePromotesInvalidBranchesToChildrenOfTheSuppliedRoot(
        ?int $parentId,
    ): void {
        $this->category::whereKey($this->key(6))->update([
            'parent_id' => $parentId === null ? null : $this->key($parentId),
        ]);

        $this->category::fixSubtree($root = $this->category::find($this->key(5)));

        $node = $this->category::find($this->key(6));

        $this->assertSame($root->getKey(), $node->getParentId());
        $this->assertSame($root->getDepth() + 1, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    /**
     * Get invalid parent keys for subtree repair.
     */
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
        $this->category::whereKey($this->key(7))->update(['parent_id' => $this->key(8)]);
        $this->category::whereKey($this->key(8))->update(['parent_id' => $this->key(7)]);

        $this->category::fixSubtree($this->category::find($this->key(5)));

        $this->assertSame($this->key(5), $this->category::find($this->key(7))->getParentId());
        $this->assertSame($this->key(7), $this->category::find($this->key(8))->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testFixSubtreePersistsRowsShiftedByItsGapUpdate(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => $this->key(1), 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'first', '_lft' => 2, '_rgt' => 3, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(3), 'name' => 'second', '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->key(1), 'depth' => 1],
        ]);

        $saving = [];

        $this->category::saving(function (Category $model) use (&$saving): void {
            $saving[] = $model->getKey();
        });

        $fixed = $this->category::fixSubtree($this->category::findOrFail($this->key(1)));

        // The gap update counts the shifted child, which repair then saves back with the grown root.
        $this->assertSame($this->keys(3, 1), $saving);
        $this->assertSame(2, $fixed);
        $this->assertSame([1, 6], $this->category::findOrFail($this->key(1))->getBounds());
        $this->assertSame([2, 3], $this->category::findOrFail($this->key(2))->getBounds());
        $this->assertSame([4, 5], $this->category::findOrFail($this->key(3))->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testFixSubtreeRejectsParentageOutsideItsStoredBounds(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => $this->key(1), 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'inside', '_lft' => 2, '_rgt' => 3, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(3), 'name' => 'outside', '_lft' => 6, '_rgt' => 7, 'parent_id' => $this->key(1), 'depth' => 1],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set subtree for [{$this->category}] with key [{$this->key(1)}] has parentage that crosses its stored bounds.",
        );

        $this->category::fixSubtree($this->category::findOrFail($this->key(1)));
    }

    #[DataProvider('subtreeOperations')]
    public function testSubtreeOperationsHydratePartialRootFromThePersistedRow(string $operation): void
    {
        $root = $this->category::query()
            ->select(['id', 'parent_id'])
            ->findOrFail($this->key(5));
        $root->setRelation('children', new Collection([new $this->category]));
        DB::flushQueryLog();

        if ($operation === 'fix') {
            $this->category::fixSubtree($root);
        } else {
            $this->category::rebuildSubtree($root, []);
        }

        $this->assertSame([8, 19], $root->getBounds());
        $this->assertSame(1, $root->getDepth());
        $this->assertFalse($root->relationLoaded('children'));
        $this->assertSame(1, $this->countStructuralIdentityReloads());
        $this->assertTreeNotBroken();
    }

    /**
     * Get the subtree repair and rebuild operations.
     */
    public static function subtreeOperations(): array
    {
        return [
            'repair' => ['fix'],
            'rebuild' => ['rebuild'],
        ];
    }

    #[DataProvider('invalidRepairRoots')]
    public function testFixSubtreeRejectsUnpersistedOrKeylessRootBeforeWriting(
        string $rootState,
        string $messageFormat,
    ): void {
        $columns = ['id', 'name', '_lft', '_rgt', 'parent_id', 'depth'];
        $before = DB::table('categories')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();

        if ($rootState === 'unpersisted') {
            $root = new $this->category;
            $root->setRawAttributes($this->category::findOrFail($this->key(5))->getAttributes());
        } else {
            $root = $this->category::query()
                ->select(['name', '_lft', '_rgt', 'parent_id', 'depth'])
                ->findOrFail($this->key(5));
        }

        try {
            $this->category::fixSubtree($root);
            $this->fail('Expected the invalid subtree root to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf($messageFormat, $this->category, $this->key(5)),
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            $before,
            DB::table('categories')
                ->orderBy('id')
                ->get($columns)
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );
    }

    /**
     * Get unpersisted or keyless roots for subtree repair.
     */
    public static function invalidRepairRoots(): array
    {
        return [
            'unpersisted root' => [
                'unpersisted',
                'Nested set subtree repair root [%s] with key [%s] must be persisted.',
            ],
            'keyless root' => [
                'keyless',
                'Nested set model [%s] requires the [id] column to be selected.',
            ],
        ];
    }

    public function testSubtreeOperationsRejectRootsFromAnotherStore(): void
    {
        $root = $this->category::findOrFail($this->key(5));
        $root->setTable('other_categories');
        $connection = DB::getDefaultConnection();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set subtree repair root [{$this->category}] uses connection [{$connection}] and table [other_categories], but query model [{$this->category}] uses connection [{$connection}] and table [categories].",
        );

        $this->category::fixSubtree($root);
    }

    #[DataProvider('invalidSubtreeRootBounds')]
    public function testSubtreeOperationsRejectInvalidStoredRootBounds(
        string $operation,
        int $lft,
        int $rgt,
    ): void {
        DB::table('categories')->where('id', '=', $this->key(5))->update([
            '_lft' => $lft,
            '_rgt' => $rgt,
        ]);
        $root = $this->category::findOrFail($this->key(5));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set subtree for [{$this->category}] with key [{$this->key(5)}] has invalid stored bounds.",
        );

        $operation === 'fix'
            ? $this->category::fixSubtree($root)
            : $this->category::rebuildSubtree($root, []);
    }

    /**
     * Get subtree operations with invalid stored root bounds.
     */
    public static function invalidSubtreeRootBounds(): array
    {
        return [
            'repair with inverted bounds' => ['fix', 9, 8],
            'rebuild with inverted bounds' => ['rebuild', 9, 8],
            'repair with non-positive bounds' => ['fix', 0, 1],
            'rebuild with non-positive bounds' => ['rebuild', 0, 1],
        ];
    }

    public function testSubtreeRepairRejectsAMissingRootThroughModelNotFound(): void
    {
        $root = $this->category::findOrFail($this->key(5));
        DB::table('categories')->where('id', '=', $this->key(5))->delete();

        $this->expectException(ModelNotFoundException::class);

        $this->category::fixSubtree($root);
    }

    public function testSubtreeRepairUsesTheCurrentPersistedRootCoordinates(): void
    {
        $root = $this->category::findOrFail($this->key(5));
        $root->setLft(2)->setRgt(3)->setDepth(99);

        $this->category::fixSubtree($root);

        $this->assertSame([8, 19], $root->getBounds());
        $this->assertSame(1, $root->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testSubtreeIsFixed(): void
    {
        $this->category::where('id', '=', $this->key(8))->update(['_lft' => 11]);

        $fixed = $this->category::fixSubtree($this->category::find($this->key(5)));
        $this->assertEquals($fixed, 1);
        $this->assertTreeNotBroken();
        $this->assertEquals($this->category::find($this->key(8))->getLft(), 12);
    }

    public function testParentIdDirtiness(): void
    {
        $node = $this->findCategory('apple');
        $node->parent_id = $this->key(5);

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

        $this->assertEqualsCanonicalizing(['apple', 'lenovo'], $result->pluck('name')->all());
    }

    public function testDescendantsEagerlyLoaded(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(2, 5))->get();

        $nodes->load('descendants');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('descendants'));
    }

    public function testNestedDescendantEagerLoadConstraintsAreDeduplicated(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(1, 5))
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('descendants');

        $queries = DB::getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(1, substr_count($relationQuery, ' between '));
        $this->assertEquals(9, $nodes->find($this->key(1))->descendants->count());
        $this->assertEquals(5, $nodes->find($this->key(5))->descendants->count());
        $this->assertTrue($nodes->find($this->key(5))->descendants->contains('id', $this->key(8)));
    }

    public function testDisjointDescendantEagerLoadConstraintsAreRetained(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(2, 5))
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('descendants');

        $queries = DB::getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(2, substr_count($relationQuery, ' between '));
        $this->assertEqualsCanonicalizing($this->keys(3, 4), $nodes->find($this->key(2))->descendants->pluck('id')->all());
        $this->assertTrue($nodes->find($this->key(5))->descendants->contains('id', $this->key(8)));
    }

    #[DataProvider('customOrderedDescendantParents')]
    public function testIndexedDescendantEagerMatchingPreservesResultOrder(array $parentIds): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(...$parentIds))
            ->defaultOrder()
            ->get();

        $nodes->load(['descendants' => fn (DescendantsRelation $query): DescendantsRelation => $query->orderBy('name')]);

        $expected = [
            1 => ['apple', 'galaxy', 'lenovo', 'lenovo', 'mobile', 'nokia', 'notebooks', 'samsung', 'sony'],
            5 => ['galaxy', 'lenovo', 'nokia', 'samsung', 'sony'],
        ];

        foreach ($parentIds as $parentId) {
            $this->assertEquals(
                $expected[$parentId],
                $nodes->find($this->key($parentId))->descendants->pluck('name')->all(),
            );
        }
    }

    /**
     * Get parent keys for custom-ordered descendant eager loading.
     */
    public static function customOrderedDescendantParents(): array
    {
        return [
            'one parent' => [[5]],
            'nested parents' => [[1, 5]],
        ];
    }

    public function testIndexedDescendantEagerMatchingPreservesDefaultResultOrder(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(2, 5))
            ->defaultOrder()
            ->get();

        $nodes->load(['descendants' => fn (DescendantsRelation $query): DescendantsRelation => $query->defaultOrder()]);

        $this->assertEquals(
            ['apple', 'lenovo'],
            $nodes->find($this->key(2))->descendants->pluck('name')->all(),
        );
        $this->assertEquals(
            ['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'],
            $nodes->find($this->key(5))->descendants->pluck('name')->all(),
        );
    }

    #[DataProvider('customOrderedAncestorParents')]
    public function testIndexedAncestorEagerMatchingPreservesResultOrder(array $parentIds): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(...$parentIds))
            ->defaultOrder()
            ->get();

        $nodes->load(['ancestors' => fn (AncestorsRelation $query): AncestorsRelation => $query->reorder('name')]);

        $expected = [
            3 => ['notebooks', 'store'],
            8 => ['mobile', 'samsung', 'store'],
        ];

        foreach ($parentIds as $parentId) {
            $this->assertEquals(
                $expected[$parentId],
                $nodes->find($this->key($parentId))->ancestors->pluck('name')->all(),
            );
        }
    }

    /**
     * Get parent keys for custom-ordered ancestor eager loading.
     */
    public static function customOrderedAncestorParents(): array
    {
        return [
            'one parent' => [[8]],
            'disjoint parents' => [[3, 8]],
        ];
    }

    public function testEagerDescendantsSkipParentsWithoutPossibleDescendants(): void
    {
        $leaves = $this->category::whereIn('id', $this->keys(3, 4, 8))->get();

        DB::flushQueryLog();

        $leaves->load('descendants');

        $this->assertSame([], DB::getQueryLog());

        foreach ($leaves as $leaf) {
            $this->assertTrue($leaf->relationLoaded('descendants'));
            $this->assertTrue($leaf->descendants->isEmpty());
        }

        $nodes = $this->category::whereIn('id', $this->keys(2, 3, 8))->get();

        DB::flushQueryLog();

        $nodes->load('descendants');

        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries);
        $this->assertEquals(1, substr_count(strtolower($queries[0]['query']), ' between '));
        $this->assertEqualsCanonicalizing($this->keys(3, 4), $nodes->find($this->key(2))->descendants->pluck('id')->all());
        $this->assertTrue($nodes->find($this->key(3))->descendants->isEmpty());
        $this->assertTrue($nodes->find($this->key(8))->descendants->isEmpty());
    }

    public function testEagerRelationsHandleMoreThanAThousandParentIntervals(): void
    {
        DB::table('categories')->delete();

        $branches = 1100;
        $rows = [
            ['id' => $this->key(1), 'name' => 'root', '_lft' => 1, '_rgt' => $branches * 4 + 2, 'parent_id' => null, 'depth' => 0],
        ];

        for ($branch = 0; $branch < $branches; ++$branch) {
            $lft = $branch * 4 + 2;
            $rows[] = ['id' => $this->key($branch * 2 + 2), 'name' => 'branch', '_lft' => $lft, '_rgt' => $lft + 3, 'parent_id' => $this->key(1), 'depth' => 1];
            $rows[] = ['id' => $this->key($branch * 2 + 3), 'name' => 'leaf', '_lft' => $lft + 1, '_rgt' => $lft + 2, 'parent_id' => $this->key($branch * 2 + 2), 'depth' => 2];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('categories')->insert($chunk);
        }

        $nodes = $this->category::defaultOrder()->get();
        $branchNodes = $nodes->where('depth', 1)->values()->load('descendants');
        $leaves = $nodes->where('depth', 2)->values()->load('ancestors');

        $this->assertSame(
            array_map(fn (int $branch): array => [$this->key($branch * 2 + 3)], range(0, $branches - 1)),
            $branchNodes->map(fn (Category $node): array => $node->descendants->modelKeys())->all(),
        );
        $this->assertSame(
            array_map(fn (int $branch): array => [$this->key(1), $this->key($branch * 2 + 2)], range(0, $branches - 1)),
            $leaves->map(fn (Category $node): array => $node->ancestors->modelKeys())->all(),
        );
    }

    public function testDescendantsRelationQuery(): void
    {
        $nodes = $this->category::has('descendants')->whereIn('id', $this->keys(2, 3))->get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals($this->key(2), $nodes->first()->getKey());

        $nodes = $this->category::has('descendants', '>', 2)->defaultOrder()->get();

        $this->assertEquals(2, $nodes->count());
        $this->assertEquals($this->key(1), $nodes[0]->getKey());
        $this->assertEquals($this->key(5), $nodes[1]->getKey());
    }

    public function testParentRelationQuery(): void
    {
        $nodes = $this->category::has('parent')->whereIn('id', $this->keys(1, 2));

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals($this->key(2), $nodes->first()->getKey());
    }

    public function testSiblingsRelation(): void
    {
        $node = $this->findCategory('samsung');
        $result = $node->siblings;

        $this->assertEquals(3, $result->count());
        $this->assertEqualsCanonicalizing($this->keys(6, 9, 10), $result->pluck('id')->all());
        $this->assertTrue($node->relationLoaded('siblings'));
    }

    public function testSiblingsAndSelfRelation(): void
    {
        $node = $this->findCategory('samsung');
        $result = $node->siblingsAndSelf;

        $this->assertEquals(4, $result->count());
        $this->assertEqualsCanonicalizing($this->keys(6, 7, 9, 10), $result->pluck('id')->all());
        $this->assertTrue($node->relationLoaded('siblingsAndSelf'));
    }

    public function testSiblingsEagerlyLoaded(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(2, 5))->get();

        $nodes->load('siblings');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('siblings'));
        $this->assertEquals($this->keys(5), $nodes->find($this->key(2))->siblings->pluck('id')->all());
        $this->assertEquals($this->keys(2), $nodes->find($this->key(5))->siblings->pluck('id')->all());
    }

    public function testSiblingEagerLoadUsesParentIdSetConstraint(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(3, 7))
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('siblings');

        $queries = DB::getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertStringContainsString(' in ', $relationQuery);
        $this->assertStringNotContainsString(' or ', $relationQuery);
        $this->assertEquals($this->keys(4), $nodes->find($this->key(3))->siblings->pluck('id')->all());
        $this->assertEqualsCanonicalizing($this->keys(6, 9, 10), $nodes->find($this->key(7))->siblings->pluck('id')->all());
    }

    public function testRootSiblingEagerLoadUsesNullParentConstraint(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(1, 11))
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('siblings');

        $queries = DB::getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertMatchesRegularExpression('/parent_id\W+is null/', $relationQuery);
        $this->assertEquals($this->keys(11), $nodes->find($this->key(1))->siblings->pluck('id')->all());
        $this->assertEquals($this->keys(1), $nodes->find($this->key(11))->siblings->pluck('id')->all());
    }

    public function testSiblingsAndSelfEagerlyLoaded(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(3, 7))->get();

        $nodes->load('siblingsAndSelf');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('siblingsAndSelf'));
        $this->assertEqualsCanonicalizing($this->keys(3, 4), $nodes->find($this->key(3))->siblingsAndSelf->pluck('id')->all());
        $this->assertEqualsCanonicalizing($this->keys(6, 7, 9, 10), $nodes->find($this->key(7))->siblingsAndSelf->pluck('id')->all());
    }

    #[DataProvider('customOrderedSiblingRelations')]
    public function testIndexedSiblingEagerMatchingPreservesResultOrder(string $relation, array $expected): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(6, 7, 9, 10))
            ->defaultOrder()
            ->get();

        $nodes->load([$relation => fn (SiblingsRelation $query): SiblingsRelation => $query->orderBy('name')]);

        $samsung = $nodes->find($this->key(7));
        $sonySiblings = $nodes->find($this->key(9))->{$relation};
        $sonyNames = $sonySiblings->pluck('name')->all();

        $this->assertEquals($expected, $samsung->{$relation}->pluck('name')->all());

        // Parents with the same siblings still receive independent collections.
        $samsung->{$relation}->pop();

        $this->assertEquals($sonyNames, $sonySiblings->pluck('name')->all());
    }

    /**
     * Get sibling relations with their expected custom-ordered names.
     */
    public static function customOrderedSiblingRelations(): array
    {
        return [
            'siblings' => ['siblings', ['lenovo', 'nokia', 'sony']],
            'siblings and self' => ['siblingsAndSelf', ['lenovo', 'nokia', 'samsung', 'sony']],
        ];
    }

    public function testSiblingsRelationQuery(): void
    {
        $this->assertEquals(
            $this->keys(3),
            $this->category::has('siblings')->whereIn('id', $this->keys(3, 8))->pluck('id')->all(),
        );

        $this->assertEquals(
            $this->keys(6, 7, 9, 10),
            $this->category::has('siblings', '>', 2)->orderBy('id')->pluck('id')->all(),
        );

        $this->assertEquals(
            $this->keys(1, 11),
            $this->category::has('siblings')->whereIn('id', $this->keys(1, 11))->orderBy('id')->pluck('id')->all(),
        );
    }

    public function testSiblingsOfRootNode(): void
    {
        $node = $this->findCategory('store');
        $result = $node->siblings;

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->key(11), $result->first()->getKey());
    }

    public function testRebuildTree(): void
    {
        $root = $this->category::findOrFail($this->key(1));

        $fixed = $this->category::rebuildTree([
            [
                'id' => $this->key(1),
                'children' => [
                    ['id' => $this->key(10)],
                    [
                        'id' => $this->key(3),
                        'name' => 'apple v2',
                        'parent_id' => $this->key(999),
                        '_lft' => 999,
                        '_rgt' => 1000,
                        'depth' => 99,
                        'children' => [['name' => 'new node']],
                    ],
                    ['id' => $this->key(2)],
                ],
            ],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $root->refreshNode();

        $this->assertSame($this->category::findOrFail($this->key(1))->getRgt(), $root->getRgt());

        $node = $this->category::find($this->key(3));

        $this->assertEquals($this->key(1), $node->getParentId());
        $this->assertEquals('apple v2', $node->name);
        $this->assertEquals(4, $node->getLft());
        $this->assertSame(1, $node->getDepth());

        $node = $this->findCategory('new node');

        $this->assertNotNull($node);
        $this->assertEquals($this->key(3), $node->getParentId());
    }

    public function testRebuildTreeHandlesNestedPayloadAttributes(): void
    {
        $this->category::rebuildTree([
            [
                'id' => $this->key(1),
                'name' => 'store v2',
                'children' => [
                    ['id' => $this->key(2), 'name' => 'notebooks v2'],
                ],
            ],
        ]);

        $this->assertTreeNotBroken();
        $this->assertSame('store v2', $this->category::find($this->key(1))->name);
        $this->assertSame('notebooks v2', $this->category::find($this->key(2))->name);
    }

    public function testUnchangedRebuildDoesNotWriteOrReloadNodeIdentity(): void
    {
        $this->resetRebuildQueryFixture();
        DB::flushQueryLog();

        $this->category::rebuildTree([
            [
                'id' => $this->key(1),
                'children' => [
                    ['id' => $this->key(2)],
                    ['id' => $this->key(3), 'children' => [['id' => $this->key(4)]]],
                ],
            ],
        ]);

        $queries = array_column(DB::getQueryLog(), 'query');

        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/^select \* from /i', $queries[0]);
    }

    public function testChangedRebuildDoesNotReloadNodeIdentityPerRow(): void
    {
        $this->resetRebuildQueryFixture();
        DB::flushQueryLog();

        $this->category::rebuildTree([
            [
                'id' => $this->key(1),
                'children' => [
                    ['id' => $this->key(4)],
                    ['id' => $this->key(2)],
                    ['id' => $this->key(3)],
                ],
            ],
        ]);

        $queries = array_column(DB::getQueryLog(), 'query');

        $this->assertFalse(collect($queries)->contains(
            static fn (string $query): bool => preg_match(
                '/^select .*_lft.*_rgt.*depth.*parent_id.*limit 1$/i',
                $query,
            ) === 1,
        ));
        $this->assertSame($this->key(1), $this->category::findOrFail($this->key(4))->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testRebuildSubtree(): void
    {
        $root = $this->category::with('children')->findOrFail($this->key(7));
        $fixed = $this->category::rebuildSubtree($root, [
            ['name' => 'new node'],
            ['id' => (string) $this->key(8)],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assertTrue($root->hasMoved());
        $this->assertFalse($root->relationLoaded('children'));
        $this->assertTreeNotBroken();

        $node = $this->findCategory('new node');

        $this->assertNotNull($node);
        $this->assertEquals($node->getLft(), 12);
    }

    public function testRebuildSubtreePersistsRowsShiftedByItsGapUpdate(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => $this->key(1), 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'first', '_lft' => 2, '_rgt' => 3, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(3), 'name' => 'second', '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->key(1), 'depth' => 1],
        ]);

        $saving = [];

        $this->category::saving(function (Category $model) use (&$saving): void {
            $saving[] = $model->getKey();
        });

        $fixed = $this->category::rebuildSubtree($this->category::findOrFail($this->key(1)), [
            ['id' => $this->key(2)],
            ['id' => $this->key(3)],
        ]);

        // Both children are saved with their data; after the gap update, only the shifted child and the grown root are.
        $this->assertSame($this->keys(2, 3, 3, 1), $saving);
        $this->assertSame(2, $fixed);
        $this->assertSame([1, 6], $this->category::findOrFail($this->key(1))->getBounds());
        $this->assertSame([2, 3], $this->category::findOrFail($this->key(2))->getBounds());
        $this->assertSame([4, 5], $this->category::findOrFail($this->key(3))->getBounds());
        $this->assertTreeNotBroken();
    }

    public function testRebuildSubtreeRejectsParentageOutsideItsStoredBounds(): void
    {
        DB::table('categories')->delete();
        DB::table('categories')->insert([
            ['id' => $this->key(1), 'name' => 'root', '_lft' => 1, '_rgt' => 4, 'parent_id' => null, 'depth' => 0],
            ['id' => $this->key(2), 'name' => 'inside', '_lft' => 2, '_rgt' => 3, 'parent_id' => $this->key(1), 'depth' => 1],
            ['id' => $this->key(3), 'name' => 'outside', '_lft' => 6, '_rgt' => 7, 'parent_id' => $this->key(1), 'depth' => 1],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set subtree for [{$this->category}] with key [{$this->key(1)}] has parentage that crosses its stored bounds.",
        );

        $this->category::rebuildSubtree($this->category::findOrFail($this->key(1)), []);
    }

    public function testRebuildSubtreeRepairsAnExistingOrphanedBranch(): void
    {
        $this->category::whereKey($this->key(8))->update(['parent_id' => $this->key(99)]);

        $this->category::rebuildSubtree($root = $this->category::find($this->key(7)), []);

        $node = $this->category::find($this->key(8));

        $this->assertSame($root->getKey(), $node->getParentId());
        $this->assertSame($root->getDepth() + 1, $node->getDepth());
        $this->assertTreeNotBroken();
    }

    public function testRebuildTreeVetoRollsBackEarlierModelWrites(): void
    {
        $columns = ['id', 'name', '_lft', '_rgt', 'parent_id', 'depth'];
        $before = DB::table('categories')
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $saves = 0;
        $vetoedKey = null;

        $this->category::saving(function (Category $model) use (&$saves, &$vetoedKey): ?bool {
            if (++$saves !== 3) {
                return null;
            }

            $vetoedKey = $model->getKey();

            return false;
        });

        try {
            DB::transaction(fn (): int => $this->category::rebuildTree([
                [
                    'id' => $this->key(1),
                    'name' => 'updated store',
                    'children' => [
                        ['id' => $this->key(11), 'name' => 'updated second store'],
                    ],
                ],
                ['id' => $this->key(2), 'name' => 'updated notebooks'],
            ]));
            $this->fail('Expected the rebuild veto to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf(
                    'Saving nested set node [%s] with key [%s] during repair was vetoed.',
                    $this->category,
                    $vetoedKey,
                ),
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            $before,
            DB::table('categories')
                ->orderBy('id')
                ->get($columns)
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );
    }

    public function testRebuildTreeWithDeletion(): void
    {
        $this->category::rebuildTree([['name' => 'all deleted']], true);

        $this->assertTreeNotBroken();

        $nodes = $this->category::get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals('all deleted', $nodes->first()->name);

        $nodes = $this->category::withTrashed()->get();

        $this->assertTrue($nodes->count() > 1);
    }

    public function testRebuildTreeSoftDeletesRemovedNodes(): void
    {
        $this->category::rebuildTree([
            ['id' => $this->key(1), 'name' => 'store'],
        ], true);

        $deleted = $this->category::withTrashed()->find($this->key(2));

        $this->assertNotNull($deleted);
        $this->assertNotNull($deleted->{$deleted->getDeletedAtColumn()});
    }

    public function testRebuildFailsWithInvalidPK(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessageIs(
            "No query results for model [{$this->category}] {$this->key(24)}",
        );

        $this->category::rebuildTree([['id' => $this->key(24)]]);
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
        $categories = $this->category::leaves();

        $this->assertEqualsCanonicalizing(
            ['apple', 'lenovo', 'nokia', 'galaxy', 'sony', 'lenovo', 'store_2'],
            $categories->pluck('name')->all(),
        );
        $this->assertTrue($categories->every->isLeaf());

        $category = $this->category::whereIsRoot()->defaultOrder()->first();

        $this->assertFalse($category->isLeaf());
    }

    public function testEagerLoadAncestors(): void
    {
        $queryLogCount = count(DB::getQueryLog());
        $categories = $this->category::with('ancestors')->orderBy('name')->get();

        $this->assertEquals($queryLogCount + 2, count(DB::getQueryLog()));

        $expectedShape = $this->expectedAncestorShape();

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function (Category $ancestor): string {
                    return "{$ancestor->name} ({$ancestor->id})";
                })->toArray())
                : '';
        }

        $this->assertEquals($expectedShape, $output);
    }

    public function testNestedAncestorEagerLoadConstraintsAreDeduplicated(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(5, 8))
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('ancestors');

        $queries = DB::getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        // Only galaxy's bound remains, so no successor search is needed.
        $this->assertStringNotContainsString('case when', $relationQuery);
        $this->assertEquals(['store'], $nodes->find($this->key(5))->ancestors->pluck('name')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $nodes->find($this->key(8))->ancestors->pluck('name')->all());
    }

    public function testDisjointAncestorEagerLoadConstraintsAreRetained(): void
    {
        $nodes = $this->category::whereIn('id', $this->keys(3, 8))
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('ancestors');

        $queries = DB::getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(1, substr_count($relationQuery, 'case when'));
        $this->assertEquals(['store', 'notebooks'], $nodes->find($this->key(3))->ancestors->pluck('name')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $nodes->find($this->key(8))->ancestors->pluck('name')->all());
    }

    public function testLazyLoadAncestors(): void
    {
        $queryLogCount = count(DB::getQueryLog());
        $categories = $this->category::orderBy('name')->get();

        $this->assertEquals($queryLogCount + 1, count(DB::getQueryLog()));

        $expectedShape = $this->expectedAncestorShape();

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function (Category $ancestor): string {
                    return "{$ancestor->name} ({$ancestor->id})";
                })->toArray())
                : '';
        }

        // assert that there is number of original query + 1 + number of rows to fulfill the relation
        $this->assertEquals($queryLogCount + 12, count(DB::getQueryLog()));

        $this->assertEquals($expectedShape, $output);
    }

    public function testWhereHasCountQueryForAncestors(): void
    {
        $categories = $this->category::has('ancestors', '>', 2)->pluck('name')->all();

        $this->assertEquals(['galaxy'], $categories);

        $categories = $this->category::whereHas('ancestors', function (EloquentBuilder $query): void {
            $query->where('id', $this->key(5));
        })->defaultOrder()->pluck('name')->all();

        $this->assertEquals(['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'], $categories);
    }

    public function testExistenceQueriesRetainTheConnectionWithoutReplicatingModels(): void
    {
        $replicatedModels = [];
        $connection = DB::getDefaultConnection();

        config([
            'database.connections.nested_set_relation' => config(
                "database.connections.{$connection}",
            ),
        ]);

        $this->category::replicating(function (Category $model) use (&$replicatedModels): void {
            $replicatedModels[] = $model;
        });

        $parent = (new $this->category)->setConnection('nested_set_relation');
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
            $this->keys(1, 5),
            $this->category::whereHas(
                'descendants',
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereHas('descendants'),
            )->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame(
            $this->keys(1, 5, 7),
            $this->category::whereHas(
                'descendants',
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereHas(
                    'ancestors',
                    fn (EloquentBuilder $query): EloquentBuilder => $query->where('name', 'samsung'),
                ),
            )->orderBy('id')->pluck('id')->all(),
        );
    }

    public function testGetsAncestorsInHierarchicalOrder(): void
    {
        $node = $this->category::find($this->key(8));

        // The relation query must be explicitly ordered by _lft (root first),
        // not left to incidental database order.
        $sql = strtolower($node->ancestors()->toSql());
        $this->assertStringContainsString('order by', $sql);
        $this->assertStringContainsString('_lft', $sql);

        // galaxy's ancestors are store (_lft 1), mobile (_lft 8), samsung (_lft 11)
        $ancestors = $node->getAncestors();
        $this->assertEquals([1, 8, 11], $ancestors->pluck('_lft')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $ancestors->pluck('name')->all());
    }

    public function testEagerLoadsAncestorsInHierarchicalOrder(): void
    {
        DB::flushQueryLog();

        $galaxy = $this->category::with('ancestors')->find($this->key(8));

        // The eager-load query for ancestors must also be ordered by _lft.
        $ancestorQuery = collect(DB::getQueryLog())
            ->first(fn (array $entry): bool => str_contains(strtolower($entry['query']), 'order by'));
        $this->assertNotNull($ancestorQuery, 'Eager ancestors query is not ordered.');
        $this->assertStringContainsString('_lft', strtolower($ancestorQuery['query']));

        $this->assertEquals([1, 8, 11], $galaxy->ancestors->pluck('_lft')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $galaxy->ancestors->pluck('name')->all());
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
        $category->parent_id = $this->key(1);
        $category->save();

        $category->refreshNode();

        $this->assertEquals($this->key(1), $category->getParentId());
    }

    public function testWhereIsRootQualifiesParentId(): void
    {
        $query = $this->category::query()
            ->join('categories as joined_categories', 'joined_categories.id', '=', 'categories.id')
            ->select('categories.id');

        $this->assertSame(
            $this->keys(1, 11),
            (clone $query)->whereIsRoot()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(2, 3, 4, 5, 6, 7, 8, 9, 10),
            (clone $query)->withoutRoot()->orderBy('categories.id')->pluck('id')->all(),
        );
        $this->assertSame(
            $this->keys(2, 3, 4, 5, 6, 7, 8, 9, 10),
            (clone $query)->hasParent()->orderBy('categories.id')->pluck('id')->all(),
        );
    }

    /**
     * Get each seeded category's ancestor path keyed by name and key.
     */
    protected function expectedAncestorShape(): array
    {
        $label = fn (string $name, int $number): string => "{$name} ({$this->key($number)})";

        return [
            $label('apple', 3) . '}' => $label('store', 1) . ' > ' . $label('notebooks', 2),
            $label('galaxy', 8) . '}' => $label('store', 1) . ' > ' . $label('mobile', 5) . ' > ' . $label('samsung', 7),
            $label('lenovo', 4) . '}' => $label('store', 1) . ' > ' . $label('notebooks', 2),
            $label('lenovo', 10) . '}' => $label('store', 1) . ' > ' . $label('mobile', 5),
            $label('mobile', 5) . '}' => $label('store', 1),
            $label('nokia', 6) . '}' => $label('store', 1) . ' > ' . $label('mobile', 5),
            $label('notebooks', 2) . '}' => $label('store', 1),
            $label('samsung', 7) . '}' => $label('store', 1) . ' > ' . $label('mobile', 5),
            $label('sony', 9) . '}' => $label('store', 1) . ' > ' . $label('mobile', 5),
            $label('store', 1) . '}' => '',
            $label('store_2', 11) . '}' => '',
        ];
    }

    /**
     * Count the logged single-row reads of a node's structural columns.
     */
    private function countStructuralIdentityReloads(): int
    {
        return count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => preg_match(
                '/^select .*_lft.*_rgt.*depth.* from .* limit 1$/i',
                $query,
            ) === 1,
        ));
    }

    /**
     * Make a persisted-looking node for in-memory collection tests.
     */
    protected function makeCollectionNode(
        int|string $id,
        int $lft,
        int $rgt,
        int|string|null $parentId,
        ?Category $node = null,
    ): Category {
        $node ??= new $this->category;
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
