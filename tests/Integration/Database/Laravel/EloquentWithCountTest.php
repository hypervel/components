<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentWithCountTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentWithCountTest extends DatabaseTestCase
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
            $table->integer('two_id');
        });

        Schema::create('four', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('one_id');
        });
    }

    public function testItBasic()
    {
        $one = Model1::create();
        $two = $one->twos()->Create();
        $two->threes()->Create();

        $results = Model1::withCount([
            'twos' => function ($query) {
                $query->where('id', '>=', 1);
            },
        ]);

        $this->assertEquals([
            ['id' => 1, 'twos_count' => 1],
        ], $results->get()->toArray());
    }

    public function testGlobalScopes()
    {
        $one = Model1::create();
        $one->fours()->create();

        $result = Model1::withCount('fours')->first();
        $this->assertEquals(0, $result->fours_count);

        $result = Model1::withCount('allFours')->first();
        $this->assertEquals(1, $result->all_fours_count);
    }

    public function testSortingScopes()
    {
        $one = Model1::create();
        $one->twos()->create();

        $query = Model1::withCount('twos')->getQuery();

        $this->assertNull($query->orders);
        $this->assertSame([], $query->getRawBindings()['order']);
    }
}

class Model1 extends Model
{
    public ?string $table = 'one';

    public bool $timestamps = false;

    protected array $guarded = [];

    public function twos()
    {
        return $this->hasMany(Model2::class, 'one_id');
    }

    public function fours()
    {
        return $this->hasMany(Model4::class, 'one_id');
    }

    public function allFours()
    {
        return $this->fours()->withoutGlobalScopes();
    }
}

class Model2 extends Model
{
    public ?string $table = 'two';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $withCount = ['threes'];

    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('app', function ($builder) {
            $builder->latest();
        });
    }

    public function threes()
    {
        return $this->hasMany(Model3::class, 'two_id');
    }
}

class Model3 extends Model
{
    public ?string $table = 'three';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('app', function ($builder) {
            $builder->where('id', '>', 0);
        });
    }
}

class Model4 extends Model
{
    public ?string $table = 'four';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('app', function ($builder) {
            $builder->where('id', '>', 1);
        });
    }
}
