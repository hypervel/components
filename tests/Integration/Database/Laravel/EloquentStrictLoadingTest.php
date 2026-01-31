<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\LazyLoadingViolationException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class EloquentStrictLoadingTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::preventLazyLoading();
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_model1', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('number')->default(1);
        });

        Schema::create('test_model2', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('model_1_id');
        });

        Schema::create('test_model3', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('model_2_id');
        });
    }

    public function testStrictModeThrowsAnExceptionOnLazyLoading()
    {
        $this->expectException(LazyLoadingViolationException::class);
        $this->expectExceptionMessage('Attempted to lazy load');

        EloquentStrictLoadingTestModel1::create();
        EloquentStrictLoadingTestModel1::create();

        $models = EloquentStrictLoadingTestModel1::get();

        $models[0]->modelTwos;
    }

    public function testStrictModeDoesntThrowAnExceptionOnLazyLoadingWithSingleModel()
    {
        EloquentStrictLoadingTestModel1::create();

        $models = EloquentStrictLoadingTestModel1::get();

        $this->assertInstanceOf(Collection::class, $models);
    }

    public function testStrictModeDoesntThrowAnExceptionOnAttributes()
    {
        EloquentStrictLoadingTestModel1::create();

        $models = EloquentStrictLoadingTestModel1::get(['id']);

        $this->assertNull($models[0]->number);
    }

    public function testStrictModeDoesntThrowAnExceptionOnEagerLoading()
    {
        $this->app['config']->set('database.connections.testing.zxc', false);

        EloquentStrictLoadingTestModel1::create();
        EloquentStrictLoadingTestModel1::create();

        $models = EloquentStrictLoadingTestModel1::with('modelTwos')->get();

        $this->assertInstanceOf(Collection::class, $models[0]->modelTwos);
    }

    public function testStrictModeDoesntThrowAnExceptionOnLazyEagerLoading()
    {
        EloquentStrictLoadingTestModel1::create();
        EloquentStrictLoadingTestModel1::create();

        $models = EloquentStrictLoadingTestModel1::get();

        $models->load('modelTwos');

        $this->assertInstanceOf(Collection::class, $models[0]->modelTwos);
    }

    public function testStrictModeDoesntThrowAnExceptionOnSingleModelLoading()
    {
        $model = EloquentStrictLoadingTestModel1::create();

        $model = EloquentStrictLoadingTestModel1::find($model->id);

        $this->assertInstanceOf(Collection::class, $model->modelTwos);
    }

    public function testStrictModeThrowsAnExceptionOnLazyLoadingInRelations()
    {
        $this->expectException(LazyLoadingViolationException::class);
        $this->expectExceptionMessage('Attempted to lazy load');

        $model1 = EloquentStrictLoadingTestModel1::create();
        EloquentStrictLoadingTestModel2::create(['model_1_id' => $model1->id]);
        EloquentStrictLoadingTestModel2::create(['model_1_id' => $model1->id]);

        $models = EloquentStrictLoadingTestModel1::with('modelTwos')->get();

        $models[0]->modelTwos[0]->modelThrees;
    }

    public function testStrictModeWithCustomCallbackOnLazyLoading()
    {
        Event::fake();

        Model::handleLazyLoadingViolationUsing(function ($model, $key) {
            event(new ViolatedLazyLoadingEvent($model, $key));
        });

        EloquentStrictLoadingTestModel1::create();
        EloquentStrictLoadingTestModel1::create();

        $models = EloquentStrictLoadingTestModel1::get();

        $models[0]->modelTwos;

        Event::assertDispatched(ViolatedLazyLoadingEvent::class);
    }

    public function testStrictModeWithOverriddenHandlerOnLazyLoading()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Violated');

        EloquentStrictLoadingTestModel1WithCustomHandler::create();
        EloquentStrictLoadingTestModel1WithCustomHandler::create();

        $models = EloquentStrictLoadingTestModel1WithCustomHandler::get();

        $models[0]->modelTwos;
    }

    public function testStrictModeDoesntThrowAnExceptionOnManuallyMadeModel()
    {
        $model1 = EloquentStrictLoadingTestModel1WithLocalPreventsLazyLoading::make();
        $model2 = EloquentStrictLoadingTestModel2::make();
        $model1->modelTwos->push($model2);

        $this->assertInstanceOf(Collection::class, $model1->modelTwos);
    }

    public function testStrictModeDoesntThrowAnExceptionOnRecentlyCreatedModel()
    {
        $model1 = EloquentStrictLoadingTestModel1WithLocalPreventsLazyLoading::create();
        $this->assertInstanceOf(Collection::class, $model1->modelTwos);
    }
}

class EloquentStrictLoadingTestModel1 extends Model
{
    protected ?string $table = 'test_model1';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function modelTwos(): HasMany
    {
        return $this->hasMany(EloquentStrictLoadingTestModel2::class, 'model_1_id');
    }
}

class EloquentStrictLoadingTestModel1WithCustomHandler extends Model
{
    protected ?string $table = 'test_model1';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function modelTwos(): HasMany
    {
        return $this->hasMany(EloquentStrictLoadingTestModel2::class, 'model_1_id');
    }

    protected function handleLazyLoadingViolation(string $key): mixed
    {
        throw new RuntimeException("Violated {$key}");
    }
}

class EloquentStrictLoadingTestModel1WithLocalPreventsLazyLoading extends Model
{
    protected ?string $table = 'test_model1';

    public bool $timestamps = false;

    public bool $preventsLazyLoading = true;

    protected array $guarded = [];

    public function modelTwos(): HasMany
    {
        return $this->hasMany(EloquentStrictLoadingTestModel2::class, 'model_1_id');
    }
}

class EloquentStrictLoadingTestModel2 extends Model
{
    protected ?string $table = 'test_model2';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function modelThrees(): HasMany
    {
        return $this->hasMany(EloquentStrictLoadingTestModel3::class, 'model_2_id');
    }
}

class EloquentStrictLoadingTestModel3 extends Model
{
    protected ?string $table = 'test_model3';

    public bool $timestamps = false;

    protected array $guarded = [];
}

class ViolatedLazyLoadingEvent
{
    public Model $model;

    public string $key;

    public function __construct(Model $model, string $key)
    {
        $this->model = $model;
        $this->key = $key;
    }
}
