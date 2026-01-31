<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentLazyEagerLoadingTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentLazyEagerLoadingTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('one', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('two', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('one_id');
        });

        Schema::create('three', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('one_id');
        });
    }

    public function testItBasic()
    {
        $one = Model1::create();
        $one->twos()->create();
        $one->threes()->create();

        $model = Model1::find($one->id);

        $this->assertTrue($model->relationLoaded('twos'));
        $this->assertFalse($model->relationLoaded('threes'));

        DB::enableQueryLog();

        $model->load('threes');

        $this->assertCount(1, DB::getQueryLog());

        $this->assertTrue($model->relationLoaded('threes'));
    }
}

class Model1 extends Model
{
    protected ?string $table = 'one';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $with = ['twos'];

    public function twos()
    {
        return $this->hasMany(Model2::class, 'one_id');
    }

    public function threes()
    {
        return $this->hasMany(Model3::class, 'one_id');
    }
}

class Model2 extends Model
{
    protected ?string $table = 'two';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function one()
    {
        return $this->belongsTo(Model1::class, 'one_id');
    }
}

class Model3 extends Model
{
    protected ?string $table = 'three';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function one()
    {
        return $this->belongsTo(Model1::class, 'one_id');
    }
}
