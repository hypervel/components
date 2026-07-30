<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\NestedSet\Models\MenuItem;
use LogicException;

class ScopedNodeTest extends TestCase
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

        DB::table('menu_items')
            ->insert($this->getMockMenuItems());

        // Reset Postgres sequence after inserting with explicit IDs
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('menu_items_id_seq', (SELECT MAX(id) FROM menu_items))");
        }
    }

    protected function getMockMenuItems(): array
    {
        return [
            ['id' => 1, 'menu_id' => 1, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1', 'depth' => 0],
            ['id' => 2, 'menu_id' => 1, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2', 'depth' => 0],
            ['id' => 5, 'menu_id' => 1, '_lft' => 4, '_rgt' => 5, 'parent_id' => 2, 'title' => 'menu item 3', 'depth' => 1],
            ['id' => 3, 'menu_id' => 2, '_lft' => 1, '_rgt' => 2, 'parent_id' => null, 'title' => 'menu item 1', 'depth' => 0],
            ['id' => 4, 'menu_id' => 2, '_lft' => 3, '_rgt' => 6, 'parent_id' => null, 'title' => 'menu item 2', 'depth' => 0],
            ['id' => 6, 'menu_id' => 2, '_lft' => 4, '_rgt' => 5, 'parent_id' => 4, 'title' => 'menu item 3', 'depth' => 1],
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

    public function testDiagnosticsRequireAConcreteScopeSelection(): void
    {
        foreach (['countErrors', 'getTotalErrors', 'isBroken'] as $method) {
            try {
                MenuItem::query()->{$method}();
                $this->fail("Expected {$method} to require a concrete scope.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('scoped([...])', $exception->getMessage());
            }
        }
    }

    public function testRepairAndRebuildRequireAConcreteScopeSelection(): void
    {
        $operations = [
            'fixTree' => fn () => MenuItem::query()->fixTree(),
            'rebuildTree' => fn () => MenuItem::query()->rebuildTree([]),
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

    public function testScalarLookupsRequireAConcreteScopeSelection(): void
    {
        $operations = [
            'whereAncestorOf' => fn () => MenuItem::query()->whereAncestorOf(5)->get(),
            'whereDescendantOf' => fn () => MenuItem::query()->whereDescendantOf(2)->get(),
            'descendantsOf' => fn () => MenuItem::query()->descendantsOf(2),
            'whereIsBefore' => fn () => MenuItem::query()->whereIsBefore(5)->get(),
            'whereIsAfter' => fn () => MenuItem::query()->whereIsAfter(5)->get(),
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

    public function testNullIsAConcreteScopeValueWhenTheAttributeIsPresent(): void
    {
        $model = new MenuItem;
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

    public function testBeforeAndAfterPredicatesUseTheExactNestedSetScope(): void
    {
        $node = MenuItem::findOrFail(4);

        $this->assertSame(
            [3],
            MenuItem::query()->whereIsBefore($node)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [6],
            MenuItem::query()->whereIsAfter($node)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            [3],
            MenuItem::scoped(['menu_id' => 2])->whereIsBefore(4)->pluck('id')->all(),
        );
        $this->assertSame(
            [],
            MenuItem::scoped(['menu_id' => 2])->whereIsBefore(2)->pluck('id')->all(),
        );
        $this->assertSame(
            [1, 3],
            MenuItem::whereKey(1)
                ->whereIsBefore($node, 'or')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
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

    public function testPredicatesRequireExactNestedSetScope(): void
    {
        $firstScopeRoot = MenuItem::findOrFail(1);
        $secondScopeRoot = MenuItem::findOrFail(3);
        $firstScopeParent = MenuItem::findOrFail(2);
        $firstScopeChild = MenuItem::findOrFail(5);
        $secondScopeChild = MenuItem::findOrFail(6);

        $this->assertTrue($firstScopeChild->isChildOf($firstScopeParent));
        $this->assertFalse($secondScopeChild->isChildOf($firstScopeParent));
        $this->assertFalse($firstScopeRoot->isSiblingOf($secondScopeRoot));
        $this->assertFalse($firstScopeRoot->isSelfOrDescendantOf($secondScopeRoot));
        $this->assertFalse($firstScopeRoot->isSelfOrAncestorOf($secondScopeRoot));
    }

    public function testSiblingsEagerMatchingUsesExactScopeBuckets(): void
    {
        $nodes = MenuItem::whereIn('id', [1, 3])
            ->orderBy('id')
            ->get();

        $nodes->load(['siblings', 'siblingsAndSelf']);

        $this->assertEquals([2], $nodes->find(1)->siblings->pluck('id')->all());
        $this->assertEquals([1, 2], $nodes->find(1)->siblingsAndSelf->pluck('id')->sort()->values()->all());
        $this->assertEquals([4], $nodes->find(3)->siblings->pluck('id')->all());
        $this->assertEquals([3, 4], $nodes->find(3)->siblingsAndSelf->pluck('id')->sort()->values()->all());
    }

    public function testRelationExistenceQueriesCorrelateExactScopes(): void
    {
        DB::table('menu_items')->insert([
            'id' => 7,
            'menu_id' => 3,
            '_lft' => 1,
            '_rgt' => 2,
            'parent_id' => null,
            'title' => 'only item',
            'depth' => 0,
        ]);

        $this->assertFalse(MenuItem::whereKey(7)->has('siblings')->exists());
        $this->assertFalse(MenuItem::whereKey(7)->has('ancestors')->exists());

        $node = MenuItem::with(['siblings', 'ancestors'])->findOrFail(7);

        $this->assertTrue($node->siblings->isEmpty());
        $this->assertTrue($node->ancestors->isEmpty());
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

    public function testStoredDepthWorksAcrossAQueryWithoutAConcreteScope(): void
    {
        $depths = MenuItem::query()
            ->withDepth()
            ->orderBy('id')
            ->pluck('depth', 'id')
            ->all();

        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 1, 6 => 1], $depths);
    }

    public function testFixTreeRepairsOnlyTheSelectedScope(): void
    {
        DB::table('menu_items')->where('id', 5)->update(['_lft' => 3]);
        $otherScope = MenuItem::scoped(['menu_id' => 2])
            ->defaultOrder()
            ->get()
            ->map
            ->getBounds()
            ->all();

        MenuItem::scoped(['menu_id' => 1])->fixTree();

        $this->assertTreeNotBroken(1);
        $this->assertSame(
            $otherScope,
            MenuItem::scoped(['menu_id' => 2])
                ->defaultOrder()
                ->get()
                ->map
                ->getBounds()
                ->all(),
        );
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

    public function testInsertionResolvesParentAfterLaterScopeAttributes(): void
    {
        $node = MenuItem::create(['parent_id' => 5, 'menu_id' => 1]);

        $this->assertSame(5, $node->getParentId());
        $this->assertSame(5, $node->getLft());
        $this->assertOtherScopeNotAffected();
    }

    public function testFillResolvesParentAfterLaterScopeAttributes(): void
    {
        $node = new MenuItem;
        $node->fill(['parent_id' => 5, 'menu_id' => 1])->save();

        $this->assertSame(5, $node->getParentId());
        $this->assertSame(5, $node->getLft());
        $this->assertOtherScopeNotAffected();
    }

    public function testInsertionToParentFromOtherScope(): void
    {
        $this->expectException(ModelNotFoundException::class);

        MenuItem::create(['menu_id' => 2, 'parent_id' => 5]);
    }

    public function testInsertionRejectsLaterScopeAttributesFromAnotherTree(): void
    {
        $this->expectException(ModelNotFoundException::class);

        MenuItem::create(['parent_id' => 5, 'menu_id' => 2]);
    }

    public function testDeletion(): void
    {
        MenuItem::find(2)->delete();

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

    protected function assertOtherScopeNotAffected(): void
    {
        $node = MenuItem::find(3);

        $this->assertEquals(1, $node->getLft());
    }

    public function testAppendingToAnotherScopeFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Nodes must be in the same tree.');

        $foo = MenuItem::find(1);
        $bar = MenuItem::find(3);

        $foo->appendToNode($bar)->save();
    }

    public function testInsertingBeforeAnotherScopeFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Nodes must be in the same tree.');

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
