<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Closure;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Casts\AsEncryptedCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class EloquentUpdateTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_model1', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('title')->nullable();
        });

        Schema::create('test_model2', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('job')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('test_model3', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('counter');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('test_model4', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('views')->default(0);
            $table->integer('likes')->default(0);
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function testBasicUpdate(): void
    {
        TestUpdateModel1::create([
            'name' => Str::random(),
            'title' => 'Ms.',
        ]);

        TestUpdateModel1::where('title', 'Ms.')->delete();

        $this->assertCount(0, TestUpdateModel1::all());
    }

    public function testUpdateWithLimitsAndOrders(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            TestUpdateModel1::create();
        }

        TestUpdateModel1::latest('id')->limit(3)->update(['title' => 'Dr.']);

        $this->assertSame('Dr.', TestUpdateModel1::find(8)->title);
        $this->assertNotSame('Dr.', TestUpdateModel1::find(7)->title);
    }

    public function testUpdatedAtWithJoins(): void
    {
        TestUpdateModel1::create([
            'name' => 'Abdul',
            'title' => 'Mr.',
        ]);

        TestUpdateModel2::create([
            'name' => Str::random(),
        ]);

        TestUpdateModel2::join('test_model1', function ($join) {
            $join->on('test_model1.id', '=', 'test_model2.id')
                ->where('test_model1.title', '=', 'Mr.');
        })->update(['test_model2.name' => 'Abdul', 'job' => 'Engineer']);

        $record = TestUpdateModel2::find(1);

        $this->assertSame('Engineer: Abdul', $record->job . ': ' . $record->name);
    }

    public function testSoftDeleteWithJoins(): void
    {
        TestUpdateModel1::create([
            'name' => Str::random(),
            'title' => 'Mr.',
        ]);

        TestUpdateModel2::create([
            'name' => Str::random(),
        ]);

        TestUpdateModel2::join('test_model1', function ($join) {
            $join->on('test_model1.id', '=', 'test_model2.id')
                ->where('test_model1.title', '=', 'Mr.');
        })->delete();

        $this->assertCount(0, TestUpdateModel2::all());
    }

    public function testIncrement(): void
    {
        TestUpdateModel3::create([
            'counter' => 0,
        ]);

        TestUpdateModel3::create([
            'counter' => 0,
        ])->delete();

        TestUpdateModel3::increment('counter');

        $models = TestUpdateModel3::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertEquals(1, $models[0]->counter);
        $this->assertEquals(0, $models[1]->counter);
    }

    public function testIncrementOrDecrementIgnoresGlobalScopes(): void
    {
        /** @var TestUpdateModel3 $deletedModel */
        $deletedModel = tap(TestUpdateModel3::create([
            'counter' => 0,
        ]), fn ($model) => $model->delete());

        $deletedModel->increment('counter');

        $this->assertEquals(1, $deletedModel->counter);

        $deletedModel->fresh();
        $this->assertEquals(1, $deletedModel->counter);

        $deletedModel->decrement('counter');
        $this->assertEquals(0, $deletedModel->fresh()->counter);
    }

    public function testUpdateSyncsPrevious(): void
    {
        $model = TestUpdateModel1::create([
            'name' => Str::random(),
            'title' => 'Ms.',
        ]);

        $model->update(['title' => 'Dr.']);

        $this->assertSame('Dr.', $model->title);
        $this->assertSame('Dr.', $model->getOriginal('title'));
        $this->assertSame(['title' => 'Dr.'], $model->getChanges());
        $this->assertSame(['title' => 'Ms.'], $model->getPrevious());
    }

    public function testSaveSyncsPrevious(): void
    {
        $model = TestUpdateModel1::create([
            'name' => Str::random(),
            'title' => 'Ms.',
        ]);

        $model->title = 'Dr.';
        $model->save();

        $this->assertSame('Dr.', $model->title);
        $this->assertSame('Dr.', $model->getOriginal('title'));
        $this->assertSame(['title' => 'Dr.'], $model->getChanges());
        $this->assertSame(['title' => 'Ms.'], $model->getPrevious());
    }

    public function testGetDirtyUsesOverriddenAttributesAccessor(): void
    {
        $model = new TestOverriddenAttributesUpdateModel;
        $model->setRawAttributes(['name' => 'Taylor'], true);
        $model->name = 'Abigail';
        TestOverriddenAttributesUpdateModel::$getAttributesCalls = 0;

        $this->assertSame(['name' => 'Abigail'], $model->getDirty());
        $this->assertSame(1, TestOverriddenAttributesUpdateModel::$getAttributesCalls);
    }

    #[DataProvider('writeSynchronizationMethods')]
    public function testWritesCallSyncChangesOverridesWithoutLeakingTheirValues(string $method): void
    {
        $model = TestSyncChangesUpdateModel::create(['name' => 'initial']);
        $copy = null;
        $model->onSyncChanges = function (Model $updated) use (&$copy): void {
            $copy = clone $updated;
        };
        $model->name = 'written';

        match ($method) {
            'save' => $model->save(),
            'increment' => $model->increment('views', 1, ['name' => 'written']),
            'incrementEach' => $model->incrementEach(['views' => 1], ['name' => 'written']),
        };

        $this->assertSame(1, $model->syncCalls);
        $this->assertSame('written', $model->getChanges()['name']);

        $copy->name = 'copied';
        $copy->syncChanges();
        $this->assertSame('copied', $copy->getChanges()['name']);

        $model->name = 'manual';
        $model->syncChanges();
        $this->assertSame('manual', $model->getChanges()['name']);
    }

    /**
     * Provide write paths that call the change synchronization hook.
     */
    public static function writeSynchronizationMethods(): array
    {
        return [
            'save' => ['save'],
            'increment' => ['increment'],
            'incrementEach' => ['incrementEach'],
        ];
    }

    public function testThrowingSyncChangesOverrideRestoresManualSynchronization(): void
    {
        $model = TestSyncChangesUpdateModel::create(['name' => 'initial']);
        $failure = new RuntimeException('Synchronization failed.');
        $model->onSyncChanges = static fn () => throw $failure;
        $caught = null;

        try {
            $model->increment('views', 1, ['name' => 'written']);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $model->name = 'manual';
        $model->syncChanges();
        $this->assertSame('manual', $model->getChanges()['name']);
    }

    public function testNestedWriteRestoresOuterSynchronizationValues(): void
    {
        $outer = TestSyncChangesUpdateModel::create(['name' => 'initial']);
        $inner = TestSyncChangesUpdateModel::create(['name' => 'inner']);
        $outer->onSyncChanges = static function (Model $model) use ($inner): void {
            $model->name = 'pending';
            $inner->increment('views', 1, ['name' => 'inner-written']);
        };

        $outer->increment('views', 1, ['name' => 'outer-written']);

        $this->assertSame('outer-written', $outer->getChanges()['name']);
        $this->assertSame('inner-written', $inner->getChanges()['name']);
        $this->assertSame('pending', $outer->name);
        $this->assertTrue($outer->isDirty('name'));
    }

    public function testSaveRetainsAllLogicalChangesWhenUpdateValuesAreFiltered(): void
    {
        $model = TestSelectiveUpdateModel::create([
            'name' => 'Taylor',
            'title' => 'Ms.',
        ]);

        $model->name = 'Abigail';
        $model->title = 'Dr.';
        $model->save();

        $stored = TestSelectiveUpdateModel::query()->findOrFail($model->getKey());

        $this->assertSame('Taylor', $stored->name);
        $this->assertSame('Dr.', $stored->title);
        $this->assertSame(['name' => 'Abigail', 'title' => 'Dr.'], $model->getChanges());
        $this->assertSame(['name' => 'Taylor', 'title' => 'Ms.'], $model->getPrevious());
    }

    public function testSaveRetainsLogicalValuesWhenUpdateValuesAreTransformed(): void
    {
        $model = TestTransformedUpdateModel::create([
            'name' => 'Taylor',
            'title' => 'Ms.',
        ]);

        $model->title = 'Dr.';
        $model->save();

        $stored = TestTransformedUpdateModel::query()->findOrFail($model->getKey());

        $this->assertSame('stored:Dr.', $stored->title);
        $this->assertSame('Dr.', $model->title);
        $this->assertSame(['title' => 'Dr.'], $model->getChanges());
        $this->assertSame(['title' => 'Ms.'], $model->getPrevious());
    }

    public function testSaveDoesNotRecomputeChangedCastValuesBeforeUpdatedEvent(): void
    {
        $model = TestCountingUpdateModel::create([
            'name' => 'Taylor',
            'title' => new TestUpdateValue('Ms.'),
        ])->fresh();

        $model->title = new TestUpdateValue('Dr.');
        TestCountingUpdateCast::$setCalls = 0;
        TestCountingUpdateModel::$setCallsAfterDirty = 0;
        $updatedState = null;

        TestCountingUpdateModel::updated(function (TestCountingUpdateModel $updated) use (&$updatedState): void {
            $updatedState = [
                'changes' => $updated->getChanges(),
                'raw' => $updated->rawTitle(),
                'set_calls_after_dirty' => TestCountingUpdateModel::$setCallsAfterDirty,
                'set_calls' => TestCountingUpdateCast::$setCalls,
                'stored' => $updated->newQuery()
                    ->whereKey($updated->getKey())
                    ->toBase()
                    ->value('title'),
            ];
        });

        $model->save();

        // Equality alone would pass without exercising the update cast at all.
        $this->assertGreaterThan(0, $updatedState['set_calls_after_dirty']);
        $this->assertSame($updatedState['set_calls_after_dirty'], $updatedState['set_calls']);
        $this->assertSame($updatedState['stored'], $updatedState['raw']);
        $this->assertSame(['title' => $updatedState['stored']], $updatedState['changes']);
    }

    public function testIncrementSyncsPrevious(): void
    {
        $model = TestUpdateModel3::create([
            'counter' => 0,
        ]);

        $model->increment('counter');

        $this->assertEquals(1, $model->counter);
        $this->assertSame(['counter' => 1], $model->getChanges());
        $this->assertSame(['counter' => 0], $model->getPrevious());
    }

    public function testIncrementEachOnModelInstanceOnlyAffectsThatRow(): void
    {
        $post1 = TestUpdateModel4::create(['views' => 10, 'likes' => 5]);
        $post2 = TestUpdateModel4::create(['views' => 50, 'likes' => 20]);
        $post3 = TestUpdateModel4::create(['views' => 100, 'likes' => 40]);

        $post1->incrementEach(['views' => 1, 'likes' => 2]);

        $this->assertEquals(11, $post1->views);
        $this->assertEquals(7, $post1->likes);

        $this->assertEquals(50, $post2->fresh()->views);
        $this->assertEquals(20, $post2->fresh()->likes);
        $this->assertEquals(100, $post3->fresh()->views);
        $this->assertEquals(40, $post3->fresh()->likes);
    }

    public function testDecrementEachOnModelInstanceOnlyAffectsThatRow(): void
    {
        $post1 = TestUpdateModel4::create(['views' => 10, 'likes' => 5]);
        $post2 = TestUpdateModel4::create(['views' => 50, 'likes' => 20]);

        $post1->decrementEach(['views' => 3, 'likes' => 2]);

        $this->assertEquals(7, $post1->views);
        $this->assertEquals(3, $post1->likes);

        $this->assertEquals(50, $post2->fresh()->views);
        $this->assertEquals(20, $post2->fresh()->likes);
    }

    public function testIncrementEachViaQueryBuilderStillAffectsAllMatchingRows(): void
    {
        TestUpdateModel4::create(['views' => 10, 'likes' => 5]);
        TestUpdateModel4::create(['views' => 50, 'likes' => 20]);

        TestUpdateModel4::incrementEach(['views' => 1]);

        $models = TestUpdateModel4::orderBy('id')->get();
        $this->assertEquals(11, $models[0]->views);
        $this->assertEquals(51, $models[1]->views);
    }

    public function testIncrementEachOnModelInstanceUpdatesTimestamps(): void
    {
        $post = TestUpdateModel4::create(['views' => 0, 'likes' => 0]);
        $originalUpdatedAt = $post->updated_at;

        $this->travel(5)->minutes();

        $post->incrementEach(['views' => 1]);

        $this->assertNotEquals($originalUpdatedAt, $post->fresh()->updated_at);
    }

    public function testIncrementEachOnSoftDeletedModelIgnoresGlobalScopes(): void
    {
        $post = tap(TestUpdateModel4::create([
            'views' => 10, 'likes' => 5,
        ]), fn ($model) => $model->delete());

        $post->incrementEach(['views' => 1, 'likes' => 1]);

        $this->assertEquals(11, $post->views);
        $this->assertEquals(6, $post->likes);

        $fresh = TestUpdateModel4::withTrashed()->find($post->id);
        $this->assertEquals(11, $fresh->views);
        $this->assertEquals(6, $fresh->likes);
    }

    public function testIncrementEachDoesNotResetUnrelatedDirtyAttributes(): void
    {
        $post = TestUpdateModel4::create(['views' => 10, 'likes' => 5, 'name' => 'Original']);

        $post->name = 'Changed';
        $post->incrementEach(['views' => 1]);

        $this->assertTrue($post->isDirty('name'));
        $this->assertSame('Changed', $post->name);
        $this->assertFalse($post->isDirty('views'));
    }

    public function testIncrementEachSyncsPrevious(): void
    {
        $post = TestUpdateModel4::create(['views' => 10, 'likes' => 5]);

        $post->incrementEach(['views' => 1, 'likes' => 2]);

        $this->assertEquals(11, $post->views);
        $this->assertEquals(7, $post->likes);
        $this->assertSame(['views' => 11, 'likes' => 7], $post->getChanges());
        $this->assertSame(['views' => 10, 'likes' => 5], $post->getPrevious());
    }

    public function testIncrementCastsExtraAttributes(): void
    {
        config(['app.key' => str_repeat('a', 32)]);

        $post = TestUpdateModel5::create(['views' => 10]);

        $post->increment('views', 1, ['name' => 'secret']);

        $this->assertNotSame('secret', TestUpdateModel5::query()->toBase()->value('name'));
        $this->assertSame('secret', $post->fresh()->name);

        $post->incrementEach(['views' => 1], ['name' => 'another secret']);

        $this->assertNotSame('another secret', TestUpdateModel5::query()->toBase()->value('name'));
        $this->assertSame('another secret', $post->fresh()->name);
    }

    #[DataProvider('encryptedIncrementCases')]
    public function testIncrementReportsStoredCastValues(string $method, bool $refresh, bool $editInPlace): void
    {
        $modelClass = $refresh ? TestRefreshingUpdateModel::class : TestUpdateModel4::class;
        $post = $modelClass::create(['name' => Crypt::encryptString('["initial"]')]);
        $post->mergeCasts(['name' => AsEncryptedCollection::class]);
        $updatedState = null;
        $editPending = $editInPlace;

        $modelClass::updating(function (Model $model) use (&$editPending): void {
            if ($editPending) {
                $model->name->push('pending');
                $editPending = false;
            }
        });

        $modelClass::updated(function (Model $model) use (&$updatedState): void {
            $updatedState = [
                'changed' => $model->getChanges()['name'],
                'stored' => $model->newQuery()->whereKey($model->getKey())->toBase()->value('name'),
            ];
        });

        $extra = ['name' => new Collection(['written'])];
        $arguments = $method === 'increment' ? ['views', 1, $extra] : [['views' => 1], $extra];
        $post->{$method}(...$arguments);

        $this->assertSame($updatedState['stored'], $updatedState['changed']);
        $this->assertSame('["written"]', Crypt::decryptString($updatedState['stored']));
        $this->assertSame($editInPlace, $post->isDirty('name'));

        if ($editInPlace) {
            $this->assertSame(['written', 'pending'], $post->name->all());
            $post->save();
            $this->assertSame(['written', 'pending'], $post->fresh()->name->all());
        }
    }

    /**
     * Provide cast writeback and refresh paths.
     */
    public static function encryptedIncrementCases(): array
    {
        return [
            'increment' => ['increment', false, false],
            'incrementEach' => ['incrementEach', false, false],
            'increment with refresh' => ['increment', true, false],
            'incrementEach with refresh' => ['incrementEach', true, false],
            'increment with refresh and pending object edit' => ['increment', true, true],
            'incrementEach with refresh and pending object edit' => ['incrementEach', true, true],
        ];
    }

    #[DataProvider('incrementObserverCases')]
    public function testIncrementExtraChangesCompareWrittenValues(string $method, string $written, string $pending): void
    {
        $post = TestUpdateModel4::create(['name' => 'initial']);

        TestUpdateModel4::updating(function (Model $model) use ($pending): void {
            $model->name = $pending;
        });

        $arguments = $method === 'increment' ? ['views', 1, ['name' => $written]] : [['views' => 1], ['name' => $written]];
        $post->{$method}(...$arguments);

        $this->assertSame($written, $post->newQuery()->whereKey($post->getKey())->toBase()->value('name'));
        $this->assertSame($written !== 'initial', $post->wasChanged('name'));
        $this->assertSame($pending, $post->name);
        $this->assertTrue($post->isDirty('name'));

        if ($written !== 'initial') {
            $this->assertSame($written, $post->getChanges()['name']);
        }
    }

    /**
     * Provide observer changes that reverse the stored value's dirty state.
     */
    public static function incrementObserverCases(): array
    {
        return [
            'increment observer restores original' => ['increment', 'changed', 'initial'],
            'incrementEach observer restores original' => ['incrementEach', 'changed', 'initial'],
            'increment observer changes unchanged extra' => ['increment', 'initial', 'changed'],
            'incrementEach observer changes unchanged extra' => ['incrementEach', 'initial', 'changed'],
        ];
    }
}

class TestUpdateModel1 extends Model
{
    public ?string $table = 'test_model1';

    public bool $timestamps = false;

    protected array $guarded = [];
}

class TestUpdateModel2 extends Model
{
    use SoftDeletes;

    public ?string $table = 'test_model2';

    protected array $fillable = ['name'];
}

class TestUpdateModel3 extends Model
{
    use SoftDeletes;

    public ?string $table = 'test_model3';

    protected array $fillable = ['counter'];

    protected array $casts = ['deleted_at' => 'datetime'];
}

class TestUpdateModel4 extends Model
{
    use SoftDeletes;

    public ?string $table = 'test_model4';

    protected array $fillable = ['views', 'likes', 'name'];

    protected array $casts = ['deleted_at' => 'datetime'];
}

class TestUpdateModel5 extends Model
{
    public ?string $table = 'test_model4';

    protected array $fillable = ['views', 'likes', 'name'];

    protected array $casts = ['name' => 'encrypted'];
}

class TestRefreshingUpdateModel extends TestUpdateModel4
{
    protected array $refreshes = ['likes'];
}

class TestSyncChangesUpdateModel extends TestUpdateModel4
{
    public int $syncCalls = 0;

    public ?Closure $onSyncChanges = null;

    /**
     * Observe synchronization and exercise a one-shot override callback.
     */
    public function syncChanges(): static
    {
        ++$this->syncCalls;

        if ($callback = $this->onSyncChanges) {
            $this->onSyncChanges = null;
            $callback($this);
        }

        return parent::syncChanges();
    }
}

class TestSelectiveUpdateModel extends TestUpdateModel1
{
    /**
     * Get the attributes that should be updated.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        return array_intersect_key(parent::getDirtyForUpdate(), ['title' => true]);
    }
}

class TestOverriddenAttributesUpdateModel extends TestUpdateModel1
{
    public static int $getAttributesCalls = 0;

    /**
     * Get all of the current attributes on the model.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        ++static::$getAttributesCalls;

        return parent::getAttributes();
    }
}

class TestTransformedUpdateModel extends TestUpdateModel1
{
    /**
     * Get the attributes that should be updated.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        $dirty = parent::getDirtyForUpdate();

        if (array_key_exists('title', $dirty)) {
            $dirty['title'] = 'stored:' . $dirty['title'];
        }

        return $dirty;
    }
}

class TestCountingUpdateModel extends TestUpdateModel1
{
    public static int $setCallsAfterDirty = 0;

    protected array $casts = [
        'title' => TestCountingUpdateCast::class,
    ];

    /**
     * Get the attributes that should be updated.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        $dirty = parent::getDirtyForUpdate();
        static::$setCallsAfterDirty = TestCountingUpdateCast::$setCalls;

        return $dirty;
    }

    /**
     * Get the raw title without merging cached casts.
     */
    public function rawTitle(): ?string
    {
        return $this->attributes['title'] ?? null;
    }
}

/** @implements CastsAttributes<TestUpdateValue, TestUpdateValue> */
class TestCountingUpdateCast implements CastsAttributes
{
    public static int $setCalls = 0;

    /**
     * Transform the stored title.
     *
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?TestUpdateValue
    {
        return $value === null
            ? null
            : new TestUpdateValue(explode(':', $value, 2)[0]);
    }

    /**
     * Transform the title for storage.
     *
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->value . ':' . ++self::$setCalls;
    }
}

class TestUpdateValue
{
    public function __construct(public string $value)
    {
    }
}
