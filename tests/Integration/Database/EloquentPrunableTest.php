<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Prunable;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Events\ModelsPruned;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Schema;
use LogicException;

/**
 * The fixtures are intentionally smaller than Laravel's because this suite
 * enforces a 60-second per-test limit and runs concurrently under ParaTest.
 * The chunk-boundary behavior and ModelsPruned event counts remain covered.
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

        collect(range(1, 1050))->map(function ($id) {
            return ['name' => 'foo'];
        })->chunk(200)->each(function ($chunk) {
            PrunableTestModel::insert($chunk->all());
        });

        $count = (new PrunableTestModel)->pruneAll();

        $this->assertEquals(1001, $count);
        $this->assertEquals(49, PrunableTestModel::count());

        Event::assertDispatched(ModelsPruned::class, 2);
    }

    public function testPrunesSoftDeletedRecords()
    {
        Event::fake();

        collect(range(1, 50))->map(function ($id) {
            return ['deleted_at' => now()];
        })->chunk(20)->each(function ($chunk) {
            PrunableSoftDeleteTestModel::insert($chunk->all());
        });

        $count = (new PrunableSoftDeleteTestModel)->pruneAll(10);

        $this->assertEquals(30, $count);
        $this->assertEquals(0, PrunableSoftDeleteTestModel::count());
        $this->assertEquals(20, PrunableSoftDeleteTestModel::withTrashed()->count());

        Event::assertDispatched(ModelsPruned::class, 3);
    }

    public function testPruneWithCustomPruneMethod()
    {
        Event::fake();

        collect(range(1, 50))->map(function ($id) {
            return ['name' => 'foo'];
        })->chunk(20)->each(function ($chunk) {
            PrunableWithCustomPruneMethodTestModel::insert($chunk->all());
        });

        $count = (new PrunableWithCustomPruneMethodTestModel)->pruneAll(10);

        $this->assertEquals(10, $count);
        // Unlike the upstream test, order explicitly because PostgreSQL does not guarantee default row order.
        $this->assertTrue((bool) PrunableWithCustomPruneMethodTestModel::orderBy('id')->first()->pruned);
        $this->assertFalse((bool) PrunableWithCustomPruneMethodTestModel::orderBy('id', 'desc')->first()->pruned);
        $this->assertEquals(50, PrunableWithCustomPruneMethodTestModel::count());

        Event::assertDispatched(ModelsPruned::class, 1);
    }

    public function testPruneWithExceptionAtOneOfModels()
    {
        Event::fake();
        Exceptions::fake();

        collect(range(1, 50))->map(function ($id) {
            return ['name' => 'foo'];
        })->chunk(20)->each(function ($chunk) {
            PrunableWithException::insert($chunk->all());
        });

        $count = (new PrunableWithException)->pruneAll(10);

        $this->assertEquals(9, $count);

        Event::assertDispatched(ModelsPruned::class, 1);
        Event::assertDispatched(fn (ModelsPruned $event) => $event->count === 9);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (Exception $exception) => $exception->getMessage() === 'foo bar');
    }
}

class PrunableTestModel extends Model
{
    use Prunable;

    public function prunable()
    {
        return $this->where('id', '<=', 1001);
    }
}

class PrunableSoftDeleteTestModel extends Model
{
    use Prunable;
    use SoftDeletes;

    public function prunable()
    {
        return $this->where('id', '<=', 30);
    }
}

class PrunableWithCustomPruneMethodTestModel extends Model
{
    use Prunable;

    public function prunable()
    {
        return $this->where('id', '<=', 10);
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
        return $this->where('id', '<=', 10);
    }

    public function prune()
    {
        if ($this->id === 5) {
            throw new Exception('foo bar');
        }
    }
}

class PrunableTestModelMissingPrunableMethod extends Model
{
    use Prunable;
}
