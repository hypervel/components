<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentHasOneThroughIntegrationTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentHasOneThroughIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB();

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    /**
     * Setup the database schema.
     */
    public function createSchema()
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->unsignedInteger('position_id')->unique()->nullable();
            $table->string('position_short');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create('contracts', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->string('title');
            $table->text('body');
            $table->string('email');
            $table->timestamps();
        });

        $this->schema()->create('positions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('shortname');
            $table->timestamps();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('contracts');
        $this->schema()->drop('positions');

        parent::tearDown();
    }

    public function testItLoadsAHasOneThroughRelationWithCustomKeys()
    {
        $this->seedData();
        $contract = Position::first()->contract;

        $this->assertSame('A title', $contract->title);
    }

    public function testItLoadsADefaultHasOneThroughRelation()
    {
        $this->migrateDefault();
        $this->seedDefaultData();

        $contract = DefaultPosition::first()->contract;
        $this->assertSame('A title', $contract->title);
        $this->assertArrayNotHasKey('email', $contract->getAttributes());

        $this->resetDefault();
    }

    public function testItLoadsARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $contract = IntermediatePosition::first()->contract;

        $this->assertSame('A title', $contract->title);
    }

    public function testEagerLoadingARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $contract = IntermediatePosition::with('contract')->first()->contract;

        $this->assertSame('A title', $contract->title);
    }

    public function testWhereHasOnARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $position = IntermediatePosition::whereHas('contract', function ($query) {
            $query->where('title', 'A title');
        })->get();

        $this->assertCount(1, $position);
    }

    public function testWithWhereHasOnARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $position = IntermediatePosition::withWhereHas('contract', function ($query) {
            $query->where('title', 'A title');
        })->get();

        $this->assertCount(1, $position);
        $this->assertTrue($position->first()->relationLoaded('contract'));
        $this->assertEquals($position->first()->contract->pluck('title')->unique()->toArray(), ['A title']);
    }

    public function testFirstOrFailThrowsAnException()
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('No query results for model [Hypervel\Tests\Database\Laravel\DatabaseEloquentHasOneThroughIntegrationTest\Contract].');

        Position::create(['id' => 1, 'name' => 'President', 'shortname' => 'ps'])
            ->user()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'position_short' => 'ps']);

        Position::first()->contract()->firstOrFail();
    }

    public function testFindOrFailThrowsAnException()
    {
        $this->expectException(ModelNotFoundException::class);

        Position::create(['id' => 1, 'name' => 'President', 'shortname' => 'ps'])
            ->user()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'position_short' => 'ps']);

        Position::first()->contract()->findOrFail(1);
    }

    public function testFirstRetrievesFirstRecord()
    {
        $this->seedData();
        $contract = Position::first()->contract()->first();

        $this->assertNotNull($contract);
        $this->assertSame('A title', $contract->title);
    }

    public function testAllColumnsAreRetrievedByDefault()
    {
        $this->seedData();
        $contract = Position::first()->contract()->first();
        $this->assertEquals([
            'id',
            'user_id',
            'title',
            'body',
            'email',
            'created_at',
            'updated_at',
            'laravel_through_key',
        ], array_keys($contract->getAttributes()));
    }

    public function testOnlyProperColumnsAreSelectedIfProvided()
    {
        $this->seedData();
        $contract = Position::first()->contract()->first(['title', 'body']);

        $this->assertEquals([
            'title',
            'body',
            'laravel_through_key',
        ], array_keys($contract->getAttributes()));
    }

    public function testChunkReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $position = Position::find(1);

        $position->contract()->chunk(10, function ($contractsChunk) {
            $contract = $contractsChunk->first();
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key', ], array_keys($contract->getAttributes()));
        });
    }

    public function testCursorReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $position = Position::find(1);

        $contracts = $position->contract()->cursor();

        foreach ($contracts as $contract) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key', ], array_keys($contract->getAttributes()));
        }
    }

    public function testEachReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $position = Position::find(1);

        $position->contract()->each(function ($contract) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key', ], array_keys($contract->getAttributes()));
        });
    }

    public function testLazyReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $position = Position::find(1);

        $position->contract()->lazy()->each(function ($contract) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key', ], array_keys($contract->getAttributes()));
        });
    }

    public function testIntermediateSoftDeletesAreIgnored()
    {
        $this->seedData();
        SoftDeletesUser::first()->delete();

        $contract = SoftDeletesPosition::first()->contract;

        $this->assertSame('A title', $contract->title);
    }

    public function testEagerLoadingLoadsRelatedModelsCorrectly()
    {
        $this->seedData();
        $position = SoftDeletesPosition::with('contract')->first();

        $this->assertSame('ps', $position->shortname);
        $this->assertSame('A title', $position->contract->title);
    }

    /**
     * Helpers...
     */
    protected function seedData()
    {
        Position::create(['id' => 1, 'name' => 'President', 'shortname' => 'ps'])
            ->user()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'position_short' => 'ps'])
            ->contract()->create(['title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com']);
    }

    protected function seedDataExtended()
    {
        $position = Position::create(['id' => 2, 'name' => 'Vice President', 'shortname' => 'vp']);
        $position->user()->create(['id' => 2, 'email' => 'example1@gmail.com', 'position_short' => 'vp'])
            ->contract()->create(
                ['title' => 'Example1 title1', 'body' => 'Example1 body1', 'email' => 'example1contract1@gmail.com']
            );
    }

    /**
     * Seed data for a default HasOneThrough setup.
     */
    protected function seedDefaultData()
    {
        DefaultPosition::create(['id' => 1, 'name' => 'President'])
            ->user()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com'])
            ->contract()->create(['title' => 'A title', 'body' => 'A body']);
    }

    /**
     * Drop the default tables.
     */
    protected function resetDefault()
    {
        $this->schema()->drop('users_default');
        $this->schema()->drop('contracts_default');
        $this->schema()->drop('positions_default');
    }

    /**
     * Migrate tables for classes with a Laravel "default" HasOneThrough setup.
     */
    protected function migrateDefault()
    {
        $this->schema()->create('users_default', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->unsignedInteger('default_position_id')->unique()->nullable();
            $table->timestamps();
        });

        $this->schema()->create('contracts_default', function ($table) {
            $table->increments('id');
            $table->integer('default_user_id')->unique();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        $this->schema()->create('positions_default', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

/**
 * Eloquent Models...
 */
class User extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOne(Contract::class, 'user_id');
    }
}

/**
 * Eloquent Models...
 */
class Contract extends Eloquent
{
    protected ?string $table = 'contracts';

    protected array $guarded = [];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

class Position extends Eloquent
{
    protected ?string $table = 'positions';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOneThrough(Contract::class, User::class, 'position_id', 'user_id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'position_id');
    }
}

/**
 * Eloquent Models...
 */
class DefaultUser extends Eloquent
{
    protected ?string $table = 'users_default';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOne(DefaultContract::class);
    }
}

/**
 * Eloquent Models...
 */
class DefaultContract extends Eloquent
{
    protected ?string $table = 'contracts_default';

    protected array $guarded = [];

    public function owner()
    {
        return $this->belongsTo(DefaultUser::class);
    }
}

class DefaultPosition extends Eloquent
{
    protected ?string $table = 'positions_default';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOneThrough(DefaultContract::class, DefaultUser::class);
    }

    public function user()
    {
        return $this->hasOne(DefaultUser::class);
    }
}

class IntermediatePosition extends Eloquent
{
    protected ?string $table = 'positions';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOneThrough(Contract::class, User::class, 'position_short', 'email', 'shortname', 'email');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'position_id');
    }
}

class SoftDeletesUser extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'users';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOne(SoftDeletesContract::class, 'user_id');
    }
}

/**
 * Eloquent Models...
 */
class SoftDeletesContract extends Eloquent
{
    protected ?string $table = 'contracts';

    protected array $guarded = [];

    public function owner()
    {
        return $this->belongsTo(SoftDeletesUser::class, 'user_id');
    }
}

class SoftDeletesPosition extends Eloquent
{
    protected ?string $table = 'positions';

    protected array $guarded = [];

    public function contract()
    {
        return $this->hasOneThrough(SoftDeletesContract::class, User::class, 'position_id', 'user_id');
    }

    public function user()
    {
        return $this->hasOne(SoftDeletesUser::class, 'position_id');
    }
}
