<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentModelLoadSumTest;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Mockery as m;

class EloquentModelLoadSumTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('base_models', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('related1s', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('base_model_id');
            $table->integer('number');
        });

        Schema::create('related2s', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('base_model_id');
            $table->integer('number');
        });

        BaseModel::create();

        Related1::create(['base_model_id' => 1, 'number' => 10]);
        Related1::create(['base_model_id' => 1, 'number' => 11]);
        Related2::create(['base_model_id' => 1, 'number' => 12]);
    }

    public function testLoadSumSingleRelation()
    {
        $model = BaseModel::first();

        DB::enableQueryLog();

        $model->loadSum('related1', 'number');

        $this->assertCount(1, DB::getQueryLog());
        $this->assertEquals(21, $model->related1_sum_number);
    }

    public function testLoadSumWithContractExpression(): void
    {
        $model = BaseModel::first();
        $expression = m::mock(Expression::class);
        $expression->shouldReceive('getValue')->andReturn('number * 2');

        DB::enableQueryLog();

        $model->loadSum('related1 as total', $expression);

        $this->assertCount(1, DB::getQueryLog());
        $this->assertEquals(42, $model->total);
    }

    public function testLoadSumMultipleRelations()
    {
        $model = BaseModel::first();

        DB::enableQueryLog();

        $model->loadSum(['related1', 'related2'], 'number');

        $this->assertCount(1, DB::getQueryLog());
        $this->assertEquals(21, $model->related1_sum_number);
        $this->assertEquals(12, $model->related2_sum_number);
    }
}

class BaseModel extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function related1()
    {
        return $this->hasMany(Related1::class);
    }

    public function related2()
    {
        return $this->hasMany(Related2::class);
    }
}

class Related1 extends Model
{
    public bool $timestamps = false;

    protected array $fillable = ['base_model_id', 'number'];

    public function parent()
    {
        return $this->belongsTo(BaseModel::class);
    }
}

class Related2 extends Model
{
    public bool $timestamps = false;

    protected array $fillable = ['base_model_id', 'number'];

    public function parent()
    {
        return $this->belongsTo(BaseModel::class);
    }
}
