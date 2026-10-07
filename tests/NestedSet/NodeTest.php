<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\HasNode;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\Fixtures\Models\Category;
use LogicException;

class NodeTest extends NodeTestBase
{
    protected string $category = Category::class;

    /**
     * Seed the fixture tree inside the test coroutine and transaction.
     */
    protected function afterRefreshingDatabase(): void
    {
        parent::afterRefreshingDatabase();

        // Reset the Postgres sequence after inserting explicit keys.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getTablePrefix() . 'categories';

            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT MAX(id) FROM {$table}))");
        }
    }

    /**
     * Get the fixture migration path.
     */
    protected function getMigrationPath(): string
    {
        return __DIR__ . '/Fixtures/migrations';
    }

    /**
     * Get the key of a seeded fixture category.
     */
    protected function key(int $number): int
    {
        return $number;
    }

    #[RequiresDatabase(['sqlite', 'pgsql'])]
    public function testIgnoredSaveOrIgnoreConflictKeepsThePendingAction(): void
    {
        $node = (new Category(['name' => 'conflict']))->forceFill(['id' => 1]);
        $node->appendToNode(Category::findOrFail(5));
        $ignored = new LogicException('The insert was ignored.');
        $caught = null;

        try {
            DB::transaction(function () use ($node, $ignored): void {
                if (! $node->saveOrIgnore()) {
                    throw $ignored;
                }
            });
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        $this->assertSame($ignored, $caught);

        unset($node->id);

        $this->assertTrue($node->save());
        $this->assertSame(5, $node->getParentId());
        $this->assertTreeNotBroken();
    }

    public function testEventedDescendantDeletionRunsChildrenFirstInChunks(): void
    {
        $deleting = [];

        EventedCategoryModel::deleting(function (EventedCategoryModel $model) use (&$deleting): void {
            $deleting[] = $model->getKey();
        });

        $node = EventedCategoryModel::findOrFail(5);
        DB::flushQueryLog();

        $node->delete();

        $this->assertSame([5, 10, 9, 8, 7, 6], $deleting);

        foreach ([5, 6, 7, 8, 9, 10] as $id) {
            $this->assertNotNull(EventedCategoryModel::withTrashed()->findOrFail($id)->deleted_at);
        }

        $chunkOrder = 'order by ' . DB::getQueryGrammar()->wrap('_lft') . ' desc limit 2';

        $this->assertSame(4, count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => str_contains($query, $chunkOrder),
        )));
    }

    public function testEventedDescendantDeletionPropagatesVetoesForTransactionRollback(): void
    {
        EventedCategoryModel::deleting(
            fn (EventedCategoryModel $model): ?bool => $model->getKey() === 8 ? false : null,
        );

        try {
            DB::transaction(fn (): int|bool|null => EventedCategoryModel::findOrFail(5)->delete());
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

    public function testSiblingsUseTheConfiguredParentColumn(): void
    {
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

    public function testNodeObjectPositionalQueriesAcceptSameStoreModelAliases(): void
    {
        $node = EventedCategoryModel::findOrFail(2);

        $this->assertSame(
            [3, 4],
            Category::query()
                ->whereDescendantOf($node)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
    }

    public function testFixTreeIgnoresVisibilityGlobalScopes(): void
    {
        $this->assertNull(GloballyScopedCategoryModel::find(8));

        Category::whereKey(8)->update(['_lft' => 999]);

        GloballyScopedCategoryModel::fixTree();

        $this->assertTreeNotBroken();
        $this->assertTrue(Category::find(8)->isDescendantOf(Category::find(7)));
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
            fn (EloquentBuilder $query): EloquentBuilder => $query->where('name', '<>', 'galaxy'),
        );
    }
}
