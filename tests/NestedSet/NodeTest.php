<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\QueryException;
use Hypervel\NestedSet\Eloquent\Collection;
use Hypervel\NestedSet\Eloquent\QueryBuilder;
use Hypervel\NestedSet\HasNode;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\NestedSet\Fixtures\Models\Category;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class NodeTest extends NodeTestBase
{
    protected string $category = Category::class;

    /**
     * Enforce SQLite foreign keys, as the server drivers do, for the constrained fixtures.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set(
            'database.connections.' . $config->string('database.default') . '.foreign_key_constraints',
            true,
        );
    }

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

    /**
     * Count the logged evented chunk queries of two nodes in the given left-bound order.
     */
    protected function countChunkQueries(string $direction): int
    {
        $order = 'order by ' . DB::getQueryGrammar()->wrap('_lft') . " {$direction} limit 2";

        return count(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $query): bool => str_contains($query, $order),
        ));
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

    public function testEventedDescendantDeletesAreChunked(): void
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

        // Five descendants in chunks of two: the short third chunk ends the loop.
        $this->assertSame(3, $this->countChunkQueries('desc'));
    }

    /**
     * @param Closure(EventedCategoryModel): mixed $delete
     */
    #[DataProvider('vetoedEventedDeletions')]
    public function testEventedDescendantDeletionVetoRollsBackEarlierDeletes(Closure $delete): void
    {
        EventedCategoryModel::deleting(
            fn (EventedCategoryModel $model): ?bool => $model->getKey() === 8 ? false : null,
        );
        $before = $this->persistedRows();

        try {
            $delete(EventedCategoryModel::findOrFail(5));
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

        $this->assertSame($before, $this->persistedRows());
    }

    /**
     * Provide transactional deletions whose veto follows earlier descendant deletes.
     *
     * @return array<string, array{Closure(EventedCategoryModel): mixed}>
     */
    public static function vetoedEventedDeletions(): array
    {
        return [
            'soft delete' => [static fn (EventedCategoryModel $node): mixed => $node->deleteOrFail()],
            'force delete' => [
                static fn (EventedCategoryModel $node): mixed => DB::transaction(fn (): mixed => $node->forceDelete()),
            ],
        ];
    }

    public function testEventedDescendantRestoresAreChunkedParentsFirst(): void
    {
        CarbonImmutable::setTestNow('2025-07-03 12:00:00');
        EventedCategoryModel::findOrFail(7)->delete();

        CarbonImmutable::setTestNow('2025-07-03 12:00:01');
        EventedCategoryModel::findOrFail(5)->delete();

        $restoring = [];
        $restored = [];

        EventedCategoryModel::restoring(function (EventedCategoryModel $model) use (&$restoring): void {
            $restoring[] = $model->getKey();
        });
        EventedCategoryModel::restored(function (EventedCategoryModel $model) use (&$restored): void {
            $restored[] = $model->getKey();
        });

        $node = EventedCategoryModel::withTrashed()->findOrFail(5);
        DB::flushQueryLog();

        $node->restore();

        $this->assertSame(2, $this->countChunkQueries('asc'));

        // Samsung and galaxy were deleted before mobile, so they stay deleted.
        $this->assertSame([5, 6, 9, 10], $restoring);
        $this->assertSame([6, 9, 10, 5], $restored);
        $this->assertSame([5, 6, 9, 10], EventedCategoryModel::whereKey([5, 6, 7, 8, 9, 10])->orderBy('id')->pluck('id')->all());
        $this->assertSame([7, 8], EventedCategoryModel::onlyTrashed()->orderBy('id')->pluck('id')->all());
    }

    public function testEventedDescendantRestorationVetoRollsBackEarlierRestores(): void
    {
        EventedCategoryModel::findOrFail(5)->delete();
        EventedCategoryModel::restoring(
            fn (EventedCategoryModel $model): ?bool => $model->getKey() === 8 ? false : null,
        );
        $before = $this->persistedRows();

        try {
            DB::transaction(fn (): bool => EventedCategoryModel::withTrashed()->findOrFail(5)->restore());
            $this->fail('Expected the descendant restoration veto to propagate.');
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf(
                    'Restoring nested set descendant [%s] with key [8] was vetoed.',
                    EventedCategoryModel::class,
                ),
                $exception->getMessage(),
            );
        }

        $this->assertSame($before, $this->persistedRows());
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

    public function testDeletingVetoRegisteredAfterBootLeavesTheSubtreeUnchanged(): void
    {
        Category::deleting(fn (Category $model): ?bool => $model->getKey() === 5 ? false : null);
        $before = $this->persistedRows();

        $this->assertFalse(Category::findOrFail(5)->forceDelete());
        $this->assertSame($before, $this->persistedRows());
    }

    /**
     * @param class-string<ConstrainedCategoryModel> $model
     */
    #[DataProvider('constrainedCategoryModels')]
    public function testHardDeletionSatisfiesARestrictingParentKey(string $model): void
    {
        $model::create(['name' => 'root', 'children' => [
            ['name' => 'branch', 'children' => [
                ['name' => 'twig', 'children' => [['name' => 'leaf']]],
                ['name' => 'other twig'],
            ]],
            ['name' => 'other branch'],
        ]]);

        $model::where('name', 'branch')->firstOrFail()->delete();

        $this->assertSame(['root', 'other branch'], $model::defaultOrder()->pluck('name')->all());
        $this->assertSame([1, 4], $model::where('name', 'root')->firstOrFail()->getBounds());
        $this->assertFalse($model::isBroken());
    }

    /**
     * Provide node models whose table restricts deleting a referenced parent.
     *
     * @return array<string, array{class-string<ConstrainedCategoryModel>}>
     */
    public static function constrainedCategoryModels(): array
    {
        return [
            'set-based' => [ConstrainedCategoryModel::class],
            'evented' => [EventedConstrainedCategoryModel::class],
        ];
    }

    public function testRebuildDeletionSatisfiesARestrictingParentKey(): void
    {
        $root = ConstrainedCategoryModel::create(['name' => 'root', 'children' => [
            ['name' => 'branch', 'children' => [
                ['name' => 'twig', 'children' => [['name' => 'leaf']]],
            ]],
            ['name' => 'other branch'],
        ]]);

        ConstrainedCategoryModel::rebuildTree([
            ['id' => $root->getKey(), 'children' => [['name' => 'new branch']]],
        ], delete: true);

        $this->assertSame(['root', 'new branch'], ConstrainedCategoryModel::defaultOrder()->pluck('name')->all());
        $this->assertFalse(ConstrainedCategoryModel::isBroken());
    }

    public function testFailedNodeDeletionRollsBackItsDeletedDescendants(): void
    {
        ConstrainedCategoryModel::create(['name' => 'root', 'children' => [
            ['name' => 'branch', 'children' => [['name' => 'leaf']]],
        ]]);
        $branch = ConstrainedCategoryModel::where('name', 'branch')->firstOrFail();
        DB::table('constrained_category_items')->insert(['category_id' => $branch->getKey()]);
        $before = $this->persistedRows('constrained_categories');

        try {
            $branch->deleteOrFail();
            $this->fail('Expected the referenced node to be kept.');
        } catch (QueryException) {
            $this->assertSame($before, $this->persistedRows('constrained_categories'));
        }
    }

    public function testHardDeletionRemovesDescendantsHiddenByGlobalScopes(): void
    {
        HiddenGalaxyCategoryModel::findOrFail(5)->delete();

        $this->assertNull(DB::table('categories')->find(8));
        $this->assertTreeNotBroken();
    }

    public function testSoftDeletionAndRestorationIncludeDescendantsHiddenByGlobalScopes(): void
    {
        GloballyScopedCategoryModel::findOrFail(5)->delete();

        $this->assertNotNull(Category::withTrashed()->findOrFail(8)->deleted_at);

        GloballyScopedCategoryModel::withTrashed()->findOrFail(5)->restore();

        $this->assertNotNull(Category::find(8));
    }

    public function testCustomModelEventResultsDoNotSkipTreeMaintenance(): void
    {
        // A non-null custom event result skips the model's ordinary listeners.
        Event::listen(CategoryLifecycleEvent::class, static fn (): bool => true);

        $node = new CustomEventCategoryModel(['name' => 'custom']);
        $node->appendToNode(CustomEventCategoryModel::findOrFail(5))->save();

        $this->assertTreeNotBroken();

        $subtree = [5, 6, 7, 8, 9, 10, $node->getKey()];

        CustomEventCategoryModel::findOrFail(5)->delete();

        $this->assertSame(0, Category::whereIn('id', $subtree)->count());

        CustomEventCategoryModel::withTrashed()->findOrFail(5)->restore();

        $this->assertSame(7, Category::whereIn('id', $subtree)->count());

        CustomEventCategoryModel::findOrFail(5)->forceDelete();

        $this->assertSame(0, Category::withTrashed()->whereIn('id', $subtree)->count());
        $this->assertTreeNotBroken();
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

    public function testColumnPatchRendersZeroHeightAsAddition(): void
    {
        $this->assertSame(
            'case when "_lft" >= 5 then "_lft" + 0 else "_lft" end',
            $this->renderColumnPatch('"_lft"', ['height' => 0, 'cut' => 5]),
        );
    }

    public function testColumnPatchRendersZeroDistanceAsAddition(): void
    {
        $this->assertSame(
            'case when "_lft" between 1 and 2 then "_lft" + 0 '
                . 'when "_lft" between 1 and 2 then "_lft" + 0 else "_lft" end',
            $this->renderColumnPatch('"_lft"', [
                'distance' => 0,
                'height' => 0,
                'lft' => 1,
                'rgt' => 2,
                'from' => 1,
                'to' => 2,
            ]),
        );
    }

    /**
     * Render a column patch for the given parameters.
     */
    private function renderColumnPatch(string $column, array $params): string
    {
        $builder = Category::query();
        $expression = (new ReflectionMethod(QueryBuilder::class, 'columnPatch'))->invoke($builder, $column, $params);

        return (string) $expression->getValue($builder->getQuery()->getGrammar());
    }

    public function testNodeDataIsRobustAgainstSelectAddingGlobalScope(): void
    {
        $data = SelectScopedCategoryModel::query()->getNodeData(3);

        $this->assertSame(['_lft', '_rgt', 'depth'], array_keys($data));
        $this->assertEquals([3, 4, 2], array_values($data));
    }

    public function testMovingNodeWithSelectAddingGlobalScope(): void
    {
        $node = SelectScopedCategoryModel::query()->findOrFail(3);

        $this->assertTrue($node->down());

        $this->assertTreeNotBroken();
        $this->assertSame(5, $node->fresh()->getLft());
    }

    public function testCountErrorsIgnoresGlobalScope(): void
    {
        $this->assertNull(GloballyScopedCategoryModel::find(8));
        $this->assertSame(Category::countErrors(), GloballyScopedCategoryModel::countErrors());
        $this->assertSame(0, GloballyScopedCategoryModel::getTotalErrors());
        $this->assertFalse(GloballyScopedCategoryModel::isBroken());

        Category::whereKey(8)->update(['_lft' => 999]);

        $errors = GloballyScopedCategoryModel::countErrors();

        $this->assertSame(1, $errors['invalid_intervals']);
        $this->assertSame(Category::countErrors(), $errors);
        $this->assertTrue(GloballyScopedCategoryModel::isBroken());
    }

    public function testFixTreeIgnoresGlobalScope(): void
    {
        // Repair sees the hidden galaxy, so a healthy tree needs no changes.
        $this->assertSame(0, GloballyScopedCategoryModel::fixTree());
        $this->assertSame(0, GloballyScopedCategoryModel::fixSubtree(GloballyScopedCategoryModel::findOrFail(7)));

        Category::whereKey(8)->update(['_lft' => 999]);

        $this->assertGreaterThan(0, GloballyScopedCategoryModel::fixTree());
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
     * Determine whether descendant model events should be fired during deletion and restoration.
     */
    protected function shouldFireDescendantEvents(): bool
    {
        return true;
    }

    /**
     * Get the number of descendants loaded per evented deletion or restoration chunk.
     */
    protected function getDescendantChunkSize(): int
    {
        return 2;
    }
}

class ConstrainedCategoryModel extends Model
{
    use HasNode;

    public bool $timestamps = false;

    protected ?string $table = 'constrained_categories';

    protected array $fillable = ['name'];
}

class EventedConstrainedCategoryModel extends ConstrainedCategoryModel
{
    /**
     * Determine whether descendant model events should be fired during deletion and restoration.
     */
    protected function shouldFireDescendantEvents(): bool
    {
        return true;
    }
}

class HiddenGalaxyCategoryModel extends Model
{
    use HasNode;

    public bool $timestamps = false;

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

class CustomEventCategoryModel extends Category
{
    protected ?string $table = 'categories';

    protected array $dispatchesEvents = [
        'saving' => CategoryLifecycleEvent::class,
        'deleting' => CategoryLifecycleEvent::class,
        'deleted' => CategoryLifecycleEvent::class,
        'restoring' => CategoryLifecycleEvent::class,
        'restored' => CategoryLifecycleEvent::class,
    ];
}

class CategoryLifecycleEvent
{
    /**
     * Create a new event instance.
     */
    public function __construct(public CustomEventCategoryModel $category)
    {
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

class SelectScopedCategoryModel extends Category
{
    protected ?string $table = 'categories';

    /**
     * Register a scope that selects an extra column.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(
            'with_extra_select',
            fn (EloquentBuilder $query): EloquentBuilder => $query->addSelect('*')->selectRaw('1 as extra_attribute'),
        );
    }
}
