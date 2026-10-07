<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\NestedSet\Eloquent\BaseRelation;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Fixtures\Models\MenuItem;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

#[ResetRefreshDatabaseState]
abstract class ScopedNodeTestBase extends TestCase
{
    use RefreshDatabase;

    /**
     * The fixture model class.
     *
     * @var class-string<MenuItem>
     */
    protected string $menuItem;

    /**
     * Get the fixture migration path.
     */
    abstract protected function getMigrationPath(): string;

    /**
     * Get the key of a seeded fixture menu item.
     */
    abstract protected function key(int $number): int|string;

    /**
     * Get the keys of seeded fixture menu items.
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
     * Seed the fixture trees inside the test coroutine and transaction.
     */
    protected function afterRefreshingDatabase(): void
    {
        DB::enableQueryLog();

        DB::table('menu_items')
            ->insert($this->getMockMenuItems());
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
     * Get the initial menu item records.
     */
    protected function getMockMenuItems(): array
    {
        return [
            ['id' => $this->key(1), 'menu_id' => 1, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1', 'depth' => 0],
            ['id' => $this->key(2), 'menu_id' => 1, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2', 'depth' => 0],
            ['id' => $this->key(5), 'menu_id' => 1, '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->key(2), 'title' => 'menu item 3', 'depth' => 1],
            ['id' => $this->key(3), 'menu_id' => 2, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1', 'depth' => 0],
            ['id' => $this->key(4), 'menu_id' => 2, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2', 'depth' => 0],
            ['id' => $this->key(6), 'menu_id' => 2, '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->key(4), 'title' => 'menu item 3', 'depth' => 1],
        ];
    }

    /**
     * Assert that the selected menu's tree has no structural errors.
     */
    protected function assertTreeNotBroken(int $menuId): void
    {
        $this->assertFalse($this->menuItem::scoped(['menu_id' => $menuId])->isBroken());
    }

    public function testNotBroken(): void
    {
        $this->assertTreeNotBroken(1);
        $this->assertTreeNotBroken(2);
    }

    public function testDiagnosticsRequireAConcreteScopeSelection(): void
    {
        foreach (['countErrors', 'getTotalErrors', 'isBroken'] as $method) {
            try {
                $this->menuItem::query()->{$method}();
                $this->fail("Expected {$method} to require a concrete scope.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('scoped([...])', $exception->getMessage());
            }
        }
    }

    public function testRepairAndRebuildRequireAConcreteScopeSelection(): void
    {
        $operations = [
            'fixTree' => fn (): int => $this->menuItem::query()->fixTree(),
            'rebuildTree' => fn (): int => $this->menuItem::query()->rebuildTree([]),
        ];

        foreach ($operations as $method => $operation) {
            try {
                $operation();
                $this->fail("Expected {$method} to require a concrete scope.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('scoped([...])', $exception->getMessage());
            }
        }
    }

    public function testSubtreeOperationHydratesPartialRootFromThePersistedRow(): void
    {
        $root = $this->menuItem::query()
            ->select(['id', '_lft', '_rgt', 'parent_id', 'depth'])
            ->findOrFail($this->key(2));

        $this->menuItem::fixSubtree($root);

        $this->assertSame(1, $root->menu_id);
        $this->assertTreeNotBroken(1);
        $this->assertOtherScopeNotAffected();
    }

    #[DataProvider('subtreeOperations')]
    public function testSubtreeOperationRejectsARootFromAnotherSelectedScope(
        string $operation,
        string $label,
    ): void {
        $root = $this->menuItem::findOrFail($this->key(4));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set {$label} root [{$this->menuItem}] does not match the query scoped([...]) selection.",
        );

        $operation === 'fix'
            ? $this->menuItem::scoped(['menu_id' => 1])->fixSubtree($root)
            : $this->menuItem::scoped(['menu_id' => 1])->rebuildSubtree($root, []);
    }

    /**
     * Get the subtree operations with their error label.
     */
    public static function subtreeOperations(): array
    {
        return [
            'repair' => ['fix', 'subtree repair'],
            'rebuild' => ['rebuild', 'subtree rebuild'],
        ];
    }

    public function testScalarLookupsRequireAConcreteScopeSelection(): void
    {
        $operations = [
            'whereAncestorOf' => fn (): BaseCollection => $this->menuItem::query()->whereAncestorOf($this->key(5))->get(),
            'whereDescendantOf' => fn (): BaseCollection => $this->menuItem::query()->whereDescendantOf($this->key(2))->get(),
            'descendantsOf' => fn (): BaseCollection => $this->menuItem::query()->descendantsOf($this->key(2)),
            'whereIsBefore' => fn (): BaseCollection => $this->menuItem::query()->whereIsBefore($this->key(5))->get(),
            'whereIsAfter' => fn (): BaseCollection => $this->menuItem::query()->whereIsAfter($this->key(5))->get(),
        ];

        foreach ($operations as $method => $operation) {
            try {
                $operation();
                $this->fail("Expected {$method} to require a concrete scope.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('scoped([...])', $exception->getMessage());
            }
        }
    }

    #[DataProvider('nodeObjectPositionalQueries')]
    public function testNodeObjectPositionalQueriesRequireSelectedScope(string $method): void
    {
        $node = $this->menuItem::query()
            ->select(['id', '_lft', '_rgt'])
            ->findOrFail($this->key(6));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->menuItem}] must have scope attribute [menu_id] selected.",
        );

        $this->menuItem::query()->{$method}($node)->get();
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

    #[DataProvider('directScopedPositionQueries')]
    public function testDirectNodePositionQueriesRequireSelectedScope(string $method): void
    {
        $node = $this->menuItem::query()
            ->select(['id', '_lft', '_rgt', 'parent_id'])
            ->findOrFail($this->key(3));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set node [{$this->menuItem}] must have scope attribute [menu_id] selected.",
        );

        $node->{$method}()->get();
    }

    /**
     * Get the node's own positional query methods.
     */
    public static function directScopedPositionQueries(): array
    {
        return [
            'next nodes' => ['nextNodes'],
            'previous nodes' => ['prevNodes'],
            'next siblings' => ['nextSiblings'],
            'previous siblings' => ['prevSiblings'],
        ];
    }

    #[DataProvider('lowLevelScopedOperations')]
    public function testLowLevelTreeOperationsRequireAConcreteScope(string $operation): void
    {
        $query = $this->menuItem::query();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('scoped([...])');

        match ($operation) {
            'lookup' => $query->getNodeData($this->key(1)),
            'movement' => $query->moveNode($this->key(1), 1),
            'depth' => $query->depthForPosition(1),
            'gap' => $query->makeGap(1, 2),
        };
    }

    /**
     * Get the low-level tree operations that need a concrete scope.
     */
    public static function lowLevelScopedOperations(): array
    {
        return [
            'node lookup' => ['lookup'],
            'movement' => ['movement'],
            'depth lookup' => ['depth'],
            'gap mutation' => ['gap'],
        ];
    }

    public function testNullIsAConcreteScopeValueWhenTheAttributeIsPresent(): void
    {
        $model = new $this->menuItem;
        $model->setRawAttributes(['menu_id' => null]);

        $this->assertSame([
            'invalid_intervals' => 0,
            'duplicate_endpoints' => 0,
            'missing_endpoints' => 0,
            'crossing_intervals' => 0,
            'missing_parent' => 0,
            'wrong_parent' => 0,
            'wrong_depth' => 0,
        ], $model->newScopedQuery()->countErrors());
    }

    public function testNewNodeRequiresItsConfiguredScopeAttribute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('attribute [menu_id] was not selected');

        (new $this->menuItem(['title' => 'missing scope']))->save();
    }

    public function testMovingNodeNotAffectingOtherMenu(): void
    {
        $node = $this->menuItem::where('menu_id', '=', 1)->defaultOrder()->first();

        $node->down();

        $node = $this->menuItem::where('menu_id', '=', 2)->defaultOrder()->first();

        $this->assertEquals(1, $node->getLft());
    }

    public function testScoped(): void
    {
        $node = $this->menuItem::scoped(['menu_id' => 2])->defaultOrder()->first();

        $this->assertEquals($this->key(3), $node->getKey());
    }

    public function testBeforeAndAfterPredicatesUseTheExactNestedSetScope(): void
    {
        $node = $this->menuItem::findOrFail($this->key(4));

        $this->assertSame(
            [$this->key(3)],
            $this->menuItem::query()->whereIsBefore($node)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [$this->key(6)],
            $this->menuItem::query()->whereIsAfter($node)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [$this->key(3)],
            $this->menuItem::scoped(['menu_id' => 2])->whereIsBefore($this->key(4))->pluck('id')->all(),
        );
        $this->assertSame(
            [],
            $this->menuItem::scoped(['menu_id' => 2])->whereIsBefore($this->key(2))->pluck('id')->all(),
        );
        $this->assertSame(
            [$this->key(1), $this->key(3)],
            $this->menuItem::whereKey($this->key(1))
                ->whereIsBefore($node, 'or')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    public function testSiblings(): void
    {
        $node = $this->menuItem::find($this->key(1));

        $result = $node->getSiblings();

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->key(2), $result->first()->getKey());

        $result = $node->getNextSiblings();

        $this->assertEquals($this->key(2), $result->first()->getKey());

        $node = $this->menuItem::find($this->key(2));

        $result = $node->getPrevSiblings();

        $this->assertEquals($this->key(1), $result->first()->getKey());
    }

    public function testPredicatesRequireExactNestedSetScope(): void
    {
        $firstScopeRoot = $this->menuItem::findOrFail($this->key(1));
        $secondScopeRoot = $this->menuItem::findOrFail($this->key(3));
        $firstScopeParent = $this->menuItem::findOrFail($this->key(2));
        $firstScopeChild = $this->menuItem::findOrFail($this->key(5));
        $secondScopeParent = $this->menuItem::findOrFail($this->key(4));

        $this->assertTrue($firstScopeChild->isChildOf($firstScopeParent));
        // Change only the scope to isolate isSameScope(), then restore it for the cross-scope interval checks.
        $firstScopeChild->menu_id = 2;
        $this->assertFalse($firstScopeChild->isChildOf($firstScopeParent));
        $firstScopeChild->menu_id = 1;
        $this->assertFalse($firstScopeRoot->isSiblingOf($secondScopeRoot));
        $this->assertFalse($firstScopeChild->isSelfOrDescendantOf($secondScopeParent));
        $this->assertFalse($secondScopeParent->isSelfOrAncestorOf($firstScopeChild));
    }

    public function testSiblingsEagerMatchingUsesExactScopeBuckets(): void
    {
        $nodes = $this->menuItem::whereIn('id', $this->keys(1, 3))
            ->orderBy('id')
            ->get();

        $nodes->load(['siblings', 'siblingsAndSelf']);

        $this->assertEquals($this->keys(2), $nodes->find($this->key(1))->siblings->pluck('id')->all());
        $this->assertEquals($this->keys(1, 2), $nodes->find($this->key(1))->siblingsAndSelf->pluck('id')->sort()->values()->all());
        $this->assertEquals($this->keys(4), $nodes->find($this->key(3))->siblings->pluck('id')->all());
        $this->assertEquals($this->keys(3, 4), $nodes->find($this->key(3))->siblingsAndSelf->pluck('id')->sort()->values()->all());
    }

    #[DataProvider('scopedRelationParents')]
    public function testRelationsRequireSelectedScopeAndParentage(
        string $relation,
        string $requiredColumn,
    ): void {
        $node = $this->menuItem::query()
            ->select(['id', '_lft', '_rgt', 'depth'])
            ->findOrFail($this->key(5));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set relation parent for [{$this->menuItem}] requires the [{$requiredColumn}] column.",
        );

        $node->{$relation}()->get();
    }

    /**
     * Get each relation with the scope column its parent requires.
     */
    public static function scopedRelationParents(): array
    {
        return [
            'ancestors' => ['ancestors', 'menu_id'],
            'descendants' => ['descendants', 'menu_id'],
            'siblings' => ['siblings', 'menu_id'],
        ];
    }

    public function testRelationExistenceQueriesCorrelateExactScopes(): void
    {
        DB::table('menu_items')->insert([
            'id' => $this->key(7),
            'menu_id' => 3,
            '_lft' => 1,
            '_rgt' => 2,
            'parent_id' => null,
            'title' => 'only item',
            'depth' => 0,
        ]);

        $this->assertFalse($this->menuItem::whereKey($this->key(7))->has('siblings')->exists());
        $this->assertSame(
            $this->keys(5),
            $this->menuItem::whereHas(
                'ancestors',
                fn (EloquentBuilder $query): EloquentBuilder => $query->whereKey($this->key(2)),
            )->orderBy('id')->pluck('id')->all(),
        );

        $node = $this->menuItem::with('siblings')->findOrFail($this->key(7));

        $this->assertTrue($node->siblings->isEmpty());
    }

    public function testEagerAncestorsHandleAnEmptyQueryResult(): void
    {
        DB::flushQueryLog();

        $node = $this->menuItem::with('ancestors')->findOrFail($this->key(1));

        $this->assertCount(2, DB::getQueryLog());
        $this->assertTrue($node->ancestors->isEmpty());
    }

    public function testDescendants(): void
    {
        $node = $this->menuItem::find($this->key(2));

        $result = $node->getDescendants();

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->key(5), $result->first()->getKey());

        $node = $this->menuItem::scoped(['menu_id' => 1])->with('descendants')->find($this->key(2));

        $result = $node->descendants;

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->key(5), $result->first()->getKey());
    }

    public function testAncestors(): void
    {
        $node = $this->menuItem::find($this->key(5));

        $result = $node->getAncestors();

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->key(2), $result->first()->getKey());

        $node = $this->menuItem::scoped(['menu_id' => 1])->with('ancestors')->find($this->key(5));

        $result = $node->ancestors;

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->key(2), $result->first()->getKey());
    }

    public function testDepth(): void
    {
        $node = $this->menuItem::scoped(['menu_id' => 1])->withDepth()->where('id', '=', $this->key(5))->first();

        $this->assertEquals(1, $node->depth);

        $node = $this->menuItem::find($this->key(2));

        $result = $node->children()->withDepth()->get();

        $this->assertEquals(1, $result->first()->depth);
    }

    public function testStoredDepthWorksAcrossAQueryWithoutAConcreteScope(): void
    {
        $depths = $this->menuItem::query()
            ->withDepth()
            ->orderBy('id')
            ->pluck('depth', 'id')
            ->all();

        $this->assertSame(
            array_combine($this->keys(1, 2, 3, 4, 5, 6), [0, 0, 0, 0, 1, 1]),
            $depths,
        );
    }

    public function testFixTreeRepairsOnlyTheSelectedScope(): void
    {
        DB::table('menu_items')->where('id', $this->key(5))->update(['_lft' => 3]);
        DB::flushQueryLog();

        $this->menuItem::scoped(['menu_id' => 1])->fixTree();

        $queries = array_column(DB::getQueryLog(), 'query');

        $this->assertTrue(collect($queries)->contains(
            static fn (string $query): bool => preg_match(
                '/^select .*menu_id.* from .*menu_items/i',
                $query,
            ) === 1,
        ));
        $this->assertFalse(collect($queries)->contains(
            static fn (string $query): bool => preg_match(
                '/^select .*_lft.*_rgt.*depth.*parent_id.*menu_id.*limit 1$/i',
                $query,
            ) === 1,
        ));
        $this->assertTreeNotBroken(1);
        $this->assertOtherScopeNotAffected();
    }

    public function testSaveAsRoot(): void
    {
        $node = $this->menuItem::find($this->key(5));

        $node->saveAsRoot();

        $this->assertEquals(5, $node->getLft());
        $this->assertEquals(null, $node->parent_id);

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertion(): void
    {
        $node = $this->menuItem::create(['menu_id' => 1, 'parent_id' => $this->key(5)]);

        $this->assertEquals($this->key(5), $node->parent_id);
        $this->assertEquals(5, $node->getLft());

        $this->assertOtherScopeNotAffected();
    }

    public function testInsertionResolvesParentAfterLaterScopeAttributes(): void
    {
        $node = $this->menuItem::create(['parent_id' => $this->key(5), 'menu_id' => 1]);

        $this->assertSame($this->key(5), $node->getParentId());
        $this->assertSame(5, $node->getLft());
        $this->assertOtherScopeNotAffected();
    }

    public function testFillResolvesParentAfterLaterScopeAttributes(): void
    {
        $node = new $this->menuItem;
        $node->fill(['parent_id' => $this->key(5), 'menu_id' => 1])->save();

        $this->assertSame($this->key(5), $node->getParentId());
        $this->assertSame(5, $node->getLft());
        $this->assertOtherScopeNotAffected();
    }

    public function testInsertionToParentFromOtherScope(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->menuItem::create(['menu_id' => 2, 'parent_id' => $this->key(5)]);
    }

    public function testInsertionRejectsLaterScopeAttributesFromAnotherTree(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->menuItem::create(['parent_id' => $this->key(5), 'menu_id' => 2]);
    }

    public function testExistingModelCannotChangeItsNestedSetScope(): void
    {
        $node = $this->menuItem::findOrFail($this->key(5));
        $node->menu_id = 2;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set scope attribute [menu_id] cannot be changed on an existing [{$this->menuItem}] model.",
        );

        $node->save();
    }

    public function testPartialExistingModelCannotHideANestedSetScopeChange(): void
    {
        $node = $this->menuItem::query()->select(['id'])->findOrFail($this->key(5));
        $node->menu_id = null;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('scope attribute [menu_id] cannot be changed');

        $node->save();
    }

    public function testSavingPersistedPartialModelRequiresASelectedKey(): void
    {
        $node = $this->menuItem::query()
            ->select(['_lft', '_rgt', 'depth', 'title'])
            ->whereKey($this->key(2))
            ->firstOrFail();
        $node->title = 'changed';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(
            "Nested set model [{$this->menuItem}] requires the [id] column to be selected.",
        );

        $node->save();
    }

    public function testNewRawMutationDefersMissingScopeFailureUntilSave(): void
    {
        $node = new $this->menuItem(['title' => 'missing scope']);
        DB::flushQueryLog();

        $node->rawNode(1, 2, null, 0);

        $this->assertSame([], DB::getQueryLog());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('attribute [menu_id] was not selected');

        $node->save();
    }

    public function testRawMutationLoadsOnlyMissingScopeIdentity(): void
    {
        $node = $this->menuItem::query()->select(['id'])->findOrFail($this->key(5));
        DB::flushQueryLog();

        $node->rawNode(4, 5, $this->key(2), 1)->save();

        $this->assertSame(1, count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => str_starts_with($query, 'select '),
        )));
        $this->assertSame(1, $node->menu_id);
        $this->assertTreeNotBroken(1);
    }

    public function testRawMutationWithCompleteScopeDoesNotReadPersistedIdentity(): void
    {
        $node = $this->menuItem::findOrFail($this->key(5));
        $node->title = 'updated';
        DB::flushQueryLog();

        $node->rawNode(
            $node->getLft(),
            $node->getRgt(),
            $node->getParentId(),
            $node->getDepth(),
        )->save();

        $this->assertSame(0, count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => str_starts_with($query, 'select '),
        )));
        $this->assertSame('updated', $this->menuItem::findOrFail($this->key(5))->title);
        $this->assertTreeNotBroken(1);
    }

    public function testSavingTheSameNestedSetScopeValueRemainsValid(): void
    {
        $node = $this->menuItem::findOrFail($this->key(5));
        $node->menu_id = 1;
        $node->title = 'updated';

        $this->assertTrue($node->save());
        $this->assertSame(1, $this->menuItem::findOrFail($this->key(5))->menu_id);
    }

    public function testDeferredNewNodeMutationRevalidatesItsScope(): void
    {
        $node = new $this->menuItem(['menu_id' => 1]);
        $node->appendToNode($this->menuItem::findOrFail($this->key(2)));
        $node->menu_id = 2;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Nodes must be in the same tree.');

        $node->save();
    }

    #[DataProvider('partialCrossScopeMutationModels')]
    public function testCrossScopeMutationHydratesPartialModels(string $partial): void
    {
        $source = $partial === 'source'
            ? $this->menuItem::query()
                ->select(['id', '_lft', '_rgt', 'parent_id', 'depth'])
                ->findOrFail($this->key(1))
            : $this->menuItem::findOrFail($this->key(1));
        $target = $partial === 'target'
            ? $this->menuItem::query()
                ->select(['id', '_lft', '_rgt', 'parent_id', 'depth'])
                ->findOrFail($this->key(3))
            : $this->menuItem::findOrFail($this->key(3));

        DB::flushQueryLog();

        $source->appendToNode($target);

        $this->assertSame([], DB::getQueryLog());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Nodes must be in the same tree.');

        $source->save();
    }

    /**
     * Get which participant of a cross-scope mutation is partially selected.
     */
    public static function partialCrossScopeMutationModels(): array
    {
        return [
            'partial source' => ['source'],
            'partial target' => ['target'],
        ];
    }

    public function testDeletion(): void
    {
        $this->menuItem::find($this->key(2))->delete();

        $node = $this->menuItem::find($this->key(1));

        $this->assertEquals(2, $node->getRgt());

        $this->assertOtherScopeNotAffected();
    }

    public function testDeletingPartiallySelectedScopedNodeHydratesItsTreeIdentity(): void
    {
        $node = $this->menuItem::query()->select(['id'])->findOrFail($this->key(2));

        $node->delete();

        $this->assertNull($this->menuItem::find($this->key(2)));
        $this->assertNull($this->menuItem::find($this->key(5)));
        $this->assertNotNull($this->menuItem::find($this->key(4)));
        $this->assertNotNull($this->menuItem::find($this->key(6)));
        $this->assertTreeNotBroken(1);
        $this->assertTreeNotBroken(2);
    }

    public function testMoving(): void
    {
        $node = $this->menuItem::find($this->key(1));
        $this->assertTrue($node->down());

        $this->assertOtherScopeNotAffected();
    }

    /**
     * Assert that the second menu's tree is unchanged.
     */
    protected function assertOtherScopeNotAffected(): void
    {
        $this->assertSame(
            [
                ['id' => $this->key(3), 'menu_id' => 2, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'depth' => 0],
                ['id' => $this->key(4), 'menu_id' => 2, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'depth' => 0],
                ['id' => $this->key(6), 'menu_id' => 2, '_lft' => 4, '_rgt' => 5, 'parent_id' => $this->key(4), 'depth' => 1],
            ],
            DB::table('menu_items')
                ->where('menu_id', 2)
                ->orderBy('id')
                ->get(['id', 'menu_id', '_lft', '_rgt', 'parent_id', 'depth'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );
    }

    public function testAppendingToAnotherScopeFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Nodes must be in the same tree.');

        $foo = $this->menuItem::find($this->key(1));
        $bar = $this->menuItem::find($this->key(3));

        $foo->appendToNode($bar)->save();
    }

    public function testInsertingBeforeAnotherScopeFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Nodes must be in the same tree.');

        $foo = $this->menuItem::find($this->key(1));
        $bar = $this->menuItem::find($this->key(3));

        $foo->insertBeforeNode($bar);
    }

    public function testEagerLoadingAncestorsWithScope(): void
    {
        $filteredNodes = $this->menuItem::where('title', 'menu item 3')->with(['ancestors'])->get();

        $this->assertEquals($this->key(2), $filteredNodes->find($this->key(5))->ancestors[0]->id);
        $this->assertEquals($this->key(4), $filteredNodes->find($this->key(6))->ancestors[0]->id);
    }

    public function testEagerLoadingDescendantsWithScope(): void
    {
        $filteredNodes = $this->menuItem::where('title', 'menu item 2')->with(['descendants'])->get();

        $this->assertEquals($this->key(5), $filteredNodes->find($this->key(2))->descendants[0]->id);
        $this->assertEquals($this->key(6), $filteredNodes->find($this->key(4))->descendants[0]->id);
    }

    #[DataProvider('scopedEagerRelatedProjections')]
    public function testScopedEagerMatchingUsesOnlyTruthfulRelatedProjections(
        string $relation,
        string $parentTitle,
        array $columns,
        array $expected,
        ?string $requiredColumn,
    ): void {
        $nodes = $this->menuItem::where('title', $parentTitle)->get();

        if ($requiredColumn !== null) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessageIs(
                "Nested set relation eager load for [{$this->menuItem}] requires the [{$requiredColumn}] column.",
            );
        }

        $nodes->load([
            $relation => fn (BaseRelation $query): BaseRelation => $query->select($columns),
        ]);

        foreach ($expected as $parentId => $relatedIds) {
            $this->assertSame(
                $this->keys(...$relatedIds),
                $nodes->find($this->key($parentId))
                    ->getRelation($relation)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all(),
            );
        }
    }

    /**
     * Get scoped eager related projections with their expected matches or missing column.
     */
    public static function scopedEagerRelatedProjections(): array
    {
        return [
            'ancestors without left bound' => [
                'ancestors',
                'menu item 3',
                ['id', 'menu_id', '_rgt'],
                [],
                '_lft',
            ],
            'ancestors without right bound' => [
                'ancestors',
                'menu item 3',
                ['id', 'menu_id', '_lft'],
                [],
                '_rgt',
            ],
            'descendants without left bound' => [
                'descendants',
                'menu item 2',
                ['id', 'menu_id', '_rgt'],
                [],
                '_lft',
            ],
            'descendants need no related right bound' => [
                'descendants',
                'menu item 2',
                ['id', 'menu_id', '_lft'],
                [2 => [5], 4 => [6]],
                null,
            ],
        ];
    }
}
