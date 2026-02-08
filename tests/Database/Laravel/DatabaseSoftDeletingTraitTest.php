<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseSoftDeletingTraitTest;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class DatabaseSoftDeletingTraitTest extends TestCase
{
    public function testDeleteSetsSoftDeletedColumn()
    {
        $model = m::mock(Stub::class);
        $model->makePartial();
        $model->shouldReceive('newModelQuery')->andReturn($query = m::mock(stdClass::class));
        $query->shouldReceive('where')->once()->with('id', '=', 1)->andReturn($query);
        $query->shouldReceive('update')->once()->with([
            'deleted_at' => 'date-time',
            'updated_at' => 'date-time',
        ]);
        $model->shouldReceive('syncOriginalAttributes')->once()->with([
            'deleted_at',
            'updated_at',
        ]);
        $model->shouldReceive('usesTimestamps')->once()->andReturn(true);
        $model->delete();

        $this->assertInstanceOf(Carbon::class, $model->deleted_at);
    }

    public function testRestore()
    {
        $model = m::mock(Stub::class);
        $model->makePartial();
        $model->shouldReceive('fireModelEvent')->with('restoring')->andReturn(true);
        $model->shouldReceive('save')->once();
        $model->shouldReceive('fireModelEvent')->with('restored', false)->andReturn(true);

        $model->restore();

        $this->assertNull($model->deleted_at);
    }

    public function testRestoreCancel()
    {
        $model = m::mock(Stub::class);
        $model->makePartial();
        $model->shouldReceive('fireModelEvent')->with('restoring')->andReturn(false);
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

    public function freshTimestamp()
    {
        return Carbon::now();
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
