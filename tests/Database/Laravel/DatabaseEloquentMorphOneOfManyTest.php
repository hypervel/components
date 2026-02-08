<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentMorphOneOfManyTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentMorphOneOfManyTest extends TestCase
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
    public function createSchema(): void
    {
        $this->schema()->create('products', function ($table) {
            $table->increments('id');
        });

        $this->schema()->create('states', function ($table) {
            $table->increments('id');
            $table->morphs('stateful');
            $table->string('state');
            $table->string('type')->nullable();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('products');
        $this->schema()->drop('states');

        parent::tearDown();
    }

    public function testEagerLoadingAppliesConstraintsToInnerJoinSubQuery()
    {
        $product = Product::create();
        $relation = $product->current_state();
        $relation->addEagerConstraints([$product]);
        $this->assertSame('select MAX("states"."id") as "id_aggregate", "states"."stateful_id", "states"."stateful_type" from "states" where "states"."stateful_type" = ? and "states"."stateful_id" = ? and "states"."stateful_id" is not null and "states"."stateful_id" in (1) and "states"."stateful_type" = ? group by "states"."stateful_id", "states"."stateful_type"', $relation->getOneOfManySubQuery()->toSql());
    }

    public function testReceivingModel()
    {
        $product = Product::create();
        $product->states()->create([
            'state' => 'draft',
        ]);
        $product->states()->create([
            'state' => 'active',
        ]);

        $this->assertNotNull($product->current_state);
        $this->assertSame('active', $product->current_state->state);
    }

    public function testMorphType()
    {
        $product = Product::create();
        $product->states()->create([
            'state' => 'draft',
        ]);
        $product->states()->create([
            'state' => 'active',
        ]);
        $state = $product->states()->make([
            'state' => 'foo',
        ]);
        $state->stateful_type = 'bar';
        $state->save();

        $this->assertNotNull($product->current_state);
        $this->assertSame('active', $product->current_state->state);
    }

    public function testForceCreateMorphType()
    {
        $product = Product::create();
        $state = $product->states()->forceCreate([
            'state' => 'active',
        ]);

        $this->assertNotNull($state);
        $this->assertSame(Product::class, $product->current_state->stateful_type);
    }

    public function testExists()
    {
        $product = Product::create();
        $previousState = $product->states()->create([
            'state' => 'draft',
        ]);
        $currentState = $product->states()->create([
            'state' => 'active',
        ]);

        $exists = Product::whereHas('current_state', function ($q) use ($previousState) {
            $q->whereKey($previousState->getKey());
        })->exists();
        $this->assertFalse($exists);

        $exists = Product::whereHas('current_state', function ($q) use ($currentState) {
            $q->whereKey($currentState->getKey());
        })->exists();
        $this->assertTrue($exists);
    }

    public function testWithWhereHas()
    {
        $product = Product::create();
        $previousState = $product->states()->create([
            'state' => 'draft',
        ]);
        $currentState = $product->states()->create([
            'state' => 'active',
        ]);

        $exists = Product::withWhereHas('current_state', function ($q) use ($previousState) {
            $q->whereKey($previousState->getKey());
        })->exists();
        $this->assertFalse($exists);

        $exists = Product::withWhereHas('current_state', function ($q) use ($currentState) {
            $q->whereKey($currentState->getKey());
        })->get();

        $this->assertCount(1, $exists);
        $this->assertTrue($exists->first()->relationLoaded('current_state'));
        $this->assertSame($exists->first()->current_state->state, $currentState->state);
    }

    public function testWithWhereRelation()
    {
        $product = Product::create();
        $currentState = $product->states()->create([
            'state' => 'active',
        ]);

        $exists = Product::withWhereRelation('current_state', 'state', 'active')->exists();
        $this->assertTrue($exists);

        $exists = Product::withWhereRelation('current_state', 'state', 'active')->get();

        $this->assertCount(1, $exists);
        $this->assertTrue($exists->first()->relationLoaded('current_state'));
        $this->assertSame($exists->first()->current_state->state, $currentState->state);
    }

    public function testWithExists()
    {
        $product = Product::create();

        $product = Product::withExists('current_state')->first();
        $this->assertFalse($product->current_state_exists);

        $product->states()->create([
            'state' => 'draft',
        ]);
        $product = Product::withExists('current_state')->first();
        $this->assertTrue($product->current_state_exists);
    }

    public function testWithExistsWithConstraintsInJoinSubSelect()
    {
        $product = Product::create();

        $product = Product::withExists('current_foo_state')->first();
        $this->assertFalse($product->current_foo_state_exists);

        $product->states()->create([
            'state' => 'draft',
            'type' => 'foo',
        ]);
        $product = Product::withExists('current_foo_state')->first();
        $this->assertTrue($product->current_foo_state_exists);
    }

    /**
     * Get a database connection instance.
     */
    protected function connection(): \Hypervel\Database\Connection
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(): \Hypervel\Database\Schema\Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}

/**
 * Eloquent Models...
 */
class Product extends Eloquent
{
    protected ?string $table = 'products';

    protected array $guarded = [];

    public bool $timestamps = false;

    public function states()
    {
        return $this->morphMany(State::class, 'stateful');
    }

    public function current_state()
    {
        return $this->morphOne(State::class, 'stateful')->ofMany();
    }

    public function current_foo_state()
    {
        return $this->morphOne(State::class, 'stateful')->ofMany(
            ['id' => 'max'],
            function ($q) {
                $q->where('type', 'foo');
            }
        );
    }
}

class State extends Eloquent
{
    protected ?string $table = 'states';

    protected array $guarded = [];

    public bool $timestamps = false;

    protected array $fillable = ['state', 'type'];
}
