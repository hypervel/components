<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Prunable;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Events\ModelsPruned;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class EloquentPrunableTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        collect([
            'prunable_test_models',
            'prunable_soft_delete_test_models',
            'prunable_test_model_missing_prunable_methods',
            'prunable_with_custom_prune_method_test_models',
            'prunable_with_exceptions',
        ])->each(function ($table) {
            Schema::create($table, function (Blueprint $table) {
                $table->increments('id');
                $table->string('name')->nullable();
                $table->softDeletes();
                $table->boolean('pruned')->default(false);
                $table->timestamps();
            });
        });
    }

    public function testPrunableMethodMustBeImplemented()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Please implement',
        );

        PrunableTestModelMissingPrunableMethod::create()->pruneAll();
    }

    public function testPrunesRecords()
    {
        Event::fake();

        collect(range(1, 5000))->map(function ($id) {
            return ['name' => 'foo'];
        })->chunk(200)->each(function ($chunk) {
            PrunableTestModel::insert($chunk->all());
        });

        $count = (new PrunableTestModel())->pruneAll();

        $this->assertEquals(1500, $count);
        $this->assertEquals(3500, PrunableTestModel::count());

        Event::assertDispatched(ModelsPruned::class, 2);
    }

    public function testPrunesSoftDeletedRecords()
    {
        Event::fake();

        collect(range(1, 5000))->map(function ($id) {
            return ['deleted_at' => now()];
        })->chunk(200)->each(function ($chunk) {
            PrunableSoftDeleteTestModel::insert($chunk->all());
        });

        $count = (new PrunableSoftDeleteTestModel())->pruneAll();

        $this->assertEquals(3000, $count);
        $this->assertEquals(0, PrunableSoftDeleteTestModel::count());
        $this->assertEquals(2000, PrunableSoftDeleteTestModel::withTrashed()->count());

        Event::assertDispatched(ModelsPruned::class, 3);
    }

    public function testPruneWithCustomPruneMethod()
    {
        Event::fake();

        collect(range(1, 5000))->map(function ($id) {
            return ['name' => 'foo'];
        })->chunk(200)->each(function ($chunk) {
            PrunableWithCustomPruneMethodTestModel::insert($chunk->all());
        });

        $count = (new PrunableWithCustomPruneMethodTestModel())->pruneAll();

        $this->assertEquals(1000, $count);
        $this->assertTrue((bool) PrunableWithCustomPruneMethodTestModel::first()->pruned);
        $this->assertFalse((bool) PrunableWithCustomPruneMethodTestModel::orderBy('id', 'desc')->first()->pruned);
        $this->assertEquals(5000, PrunableWithCustomPruneMethodTestModel::count());

        Event::assertDispatched(ModelsPruned::class, 1);
    }

    public function testPruneWithExceptionAtOneOfModels()
    {
        Event::fake();
        Exceptions::fake();

        collect(range(1, 5000))->map(function ($id) {
            return ['name' => 'foo'];
        })->chunk(200)->each(function ($chunk) {
            PrunableWithException::insert($chunk->all());
        });

        $count = (new PrunableWithException())->pruneAll();

        $this->assertEquals(999, $count);

        Event::assertDispatched(ModelsPruned::class, 1);
        Event::assertDispatched(fn (ModelsPruned $event) => $event->count === 999);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (Exception $exception) => $exception->getMessage() === 'foo bar');
    }
}

class PrunableTestModel extends Model
{
    use Prunable;

    public function prunable()
    {
        return $this->where('id', '<=', 1500);
    }
}

class PrunableSoftDeleteTestModel extends Model
{
    use Prunable;
    use SoftDeletes;

    public function prunable()
    {
        return $this->where('id', '<=', 3000);
    }
}

class PrunableWithCustomPruneMethodTestModel extends Model
{
    use Prunable;

    public function prunable()
    {
        return $this->where('id', '<=', 1000);
    }

    public function prune()
    {
        $this->forceFill([
            'pruned' => true,
        ])->save();
    }
}

class PrunableWithException extends Model
{
    use Prunable;

    public function prunable()
    {
        return $this->where('id', '<=', 1000);
    }

    public function prune()
    {
        if ($this->id === 500) {
            throw new Exception('foo bar');
        }
    }
}

class PrunableTestModelMissingPrunableMethod extends Model
{
    use Prunable;
}
