<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseSoftDeletingTraitTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseSoftDeletingTraitTest extends TestCase
{
    public function testDeleteSetsSoftDeletedColumn(): void
    {
        $model = m::mock(Stub::class)->makePartial();
        $query = m::mock(Builder::class);
        $model->expects('newModelQuery')->andReturn($query);
        $query->expects('where')->with('id', '=', 1)->andReturn($query);
        $query->expects('update')->with([
            'deleted_at' => 'date-time',
            'updated_at' => 'date-time',
        ]);
        $model->expects('syncOriginalAttributes')->with([
            'deleted_at',
            'updated_at',
        ]);
        $model->expects('usesTimestamps')->andReturn(true);
        $model->delete();

        $this->assertSame(CarbonImmutable::class, $model->deleted_at::class);
    }

    public function testForceDeleteWrappersPreserveIntegerDeleteResult(): void
    {
        $model = new IntegerDeleteResultModelStub;
        $model->exists = true;

        $this->assertSame(1, $model->forceDelete());
        $this->assertSame(1, $model->forceDeleteQuietly());
    }

    public function testRestore(): void
    {
        $model = m::mock(Stub::class)->makePartial();
        $model->expects('fireModelEvent')->with('restoring')->andReturn(true);
        $model->expects('save');

        $model->restore();

        $this->assertNull($model->deleted_at);
    }

    public function testRestoreCancel(): void
    {
        $model = m::mock(Stub::class)->makePartial();
        $model->expects('fireModelEvent')->with('restoring')->andReturn(false);
        $model->shouldReceive('save')->never();

        $this->assertFalse($model->restore());
    }
}

class Stub
{
    use SoftDeletes;

    public $deleted_at;

    public $updated_at;

    public $timestamps = true;

    public $exists = false;

    public function newQuery()
    {
    }

    public function getKey()
    {
        return 1;
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function save(): bool
    {
        return true;
    }

    public function delete()
    {
        return $this->performDeleteOnModel();
    }

    public function fireModelEvent()
    {
    }

    public function freshTimestamp(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function fromDateTime()
    {
        return 'date-time';
    }

    public function getUpdatedAtColumn()
    {
        return defined('static::UPDATED_AT') ? static::UPDATED_AT : 'updated_at';
    }

    public function setKeysForSaveQuery($query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }

    protected function getKeyForSaveQuery()
    {
        return 1;
    }
}

class IntegerDeleteResultModelStub extends Model
{
    use SoftDeletes;

    /**
     * Return a simulated affected-row count.
     */
    public function delete(): int
    {
        return 1;
    }
}
