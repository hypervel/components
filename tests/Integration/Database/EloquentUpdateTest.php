<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;

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
    }

    public function testBasicUpdate()
    {
        TestUpdateModel1::create([
            'name' => Str::random(),
            'title' => 'Ms.',
        ]);

        TestUpdateModel1::where('title', 'Ms.')->delete();

        $this->assertCount(0, TestUpdateModel1::all());
    }

    public function testUpdateWithLimitsAndOrders()
    {
        if ($this->driver === 'sqlsrv') {
            $this->markTestSkipped('The limit keyword is not supported on MSSQL.');
        }

        for ($i = 1; $i <= 10; ++$i) {
            TestUpdateModel1::create();
        }

        TestUpdateModel1::latest('id')->limit(3)->update(['title' => 'Dr.']);

        $this->assertSame('Dr.', TestUpdateModel1::find(8)->title);
        $this->assertNotSame('Dr.', TestUpdateModel1::find(7)->title);
    }

    public function testUpdatedAtWithJoins()
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

    public function testSoftDeleteWithJoins()
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

    public function testIncrement()
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

    public function testIncrementOrDecrementIgnoresGlobalScopes()
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

    public function testUpdateSyncsPrevious()
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

    public function testSaveSyncsPrevious()
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

    public function testIncrementSyncsPrevious()
    {
        $model = TestUpdateModel3::create([
            'counter' => 0,
        ]);

        $model->increment('counter');

        $this->assertEquals(1, $model->counter);
        $this->assertSame(['counter' => 1], $model->getChanges());
        $this->assertSame(['counter' => 0], $model->getPrevious());
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
