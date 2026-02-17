<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentHasOneOfManyTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentHasOneOfManyTest extends TestCase
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
        });

        $this->schema()->create('logins', function ($table) {
            $table->increments('id');
            $table->foreignId('user_id');
            $table->dateTime('deleted_at')->nullable();
        });

        $this->schema()->create('states', function ($table) {
            $table->increments('id');
            $table->string('state');
            $table->string('type');
            $table->foreignId('user_id');
            $table->timestamps();
        });

        $this->schema()->create('prices', function ($table) {
            $table->increments('id');
            $table->dateTime('published_at');
            $table->foreignId('user_id');
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('logins');
        $this->schema()->drop('states');
        $this->schema()->drop('prices');

        parent::tearDown();
    }

    public function testItGuessesRelationName()
    {
        $user = User::make();
        $this->assertSame('latest_login', $user->latest_login()->getRelationName());
    }

    public function testItGuessesRelationNameAndAddsOfManyWhenTableNameIsRelationName()
    {
        $model = TestModel::make();
        $this->assertSame('logins_of_many', $model->logins()->getRelationName());
    }

    public function testRelationNameCanBeSet()
    {
        $user = User::create();

        // Using "ofMany"
        $relation = $user->latest_login()->ofMany('id', 'max', 'foo');
        $this->assertSame('foo', $relation->getRelationName());

        // Using "latestOfMAny"
        $relation = $user->latest_login()->latestOfMAny('id', 'bar');
        $this->assertSame('bar', $relation->getRelationName());

        // Using "oldestOfMAny"
        $relation = $user->latest_login()->oldestOfMAny('id', 'baz');
        $this->assertSame('baz', $relation->getRelationName());
    }

    public function testCorrectLatestOfManyQuery(): void
    {
        $user = User::create();
        $relation = $user->latest_login();
        $this->assertSame('select "logins".* from "logins" inner join (select MAX("logins"."id") as "id_aggregate", "logins"."user_id" from "logins" where "logins"."user_id" = ? and "logins"."user_id" is not null group by "logins"."user_id") as "latest_login" on "latest_login"."id_aggregate" = "logins"."id" and "latest_login"."user_id" = "logins"."user_id" where "logins"."user_id" = ? and "logins"."user_id" is not null', $relation->getQuery()->toSql());
    }

    public function testEagerLoadingAppliesConstraintsToInnerJoinSubQuery()
    {
        $user = User::create();
        $relation = $user->latest_login();
        $relation->addEagerConstraints([$user]);
        $this->assertSame('select MAX("logins"."id") as "id_aggregate", "logins"."user_id" from "logins" where "logins"."user_id" = ? and "logins"."user_id" is not null and "logins"."user_id" in (1) group by "logins"."user_id"', $relation->getOneOfManySubQuery()->toSql());
    }

    public function testGlobalScopeIsNotAppliedWhenRelationIsDefinedWithoutGlobalScope()
    {
        Login::addGlobalScope('test', function ($query) {
            $query->orderBy('id');
        });

        $user = User::create();
        $relation = $user->latest_login_without_global_scope();
        $relation->addEagerConstraints([$user]);
        $this->assertSame('select "logins".* from "logins" inner join (select MAX("logins"."id") as "id_aggregate", "logins"."user_id" from "logins" where "logins"."user_id" = ? and "logins"."user_id" is not null and "logins"."user_id" in (1) group by "logins"."user_id") as "latestOfMany" on "latestOfMany"."id_aggregate" = "logins"."id" and "latestOfMany"."user_id" = "logins"."user_id" where "logins"."user_id" = ? and "logins"."user_id" is not null', $relation->getQuery()->toSql());

        Login::addGlobalScope('test', function ($query) {
        });
    }

    public function testGlobalScopeIsNotAppliedWhenRelationIsDefinedWithoutGlobalScopeWithComplexQuery()
    {
        Price::addGlobalScope('test', function ($query) {
            $query->orderBy('id');
        });

        $user = User::create();
        $relation = $user->price_without_global_scope();
        $this->assertSame('select "prices".* from "prices" inner join (select max("prices"."id") as "id_aggregate", min("prices"."published_at") as "published_at_aggregate", "prices"."user_id" from "prices" inner join (select max("prices"."published_at") as "published_at_aggregate", "prices"."user_id" from "prices" where "published_at" < ? and "prices"."user_id" = ? and "prices"."user_id" is not null group by "prices"."user_id") as "price_without_global_scope" on "price_without_global_scope"."published_at_aggregate" = "prices"."published_at" and "price_without_global_scope"."user_id" = "prices"."user_id" where "published_at" < ? group by "prices"."user_id") as "price_without_global_scope" on "price_without_global_scope"."id_aggregate" = "prices"."id" and "price_without_global_scope"."published_at_aggregate" = "prices"."published_at" and "price_without_global_scope"."user_id" = "prices"."user_id" where "prices"."user_id" = ? and "prices"."user_id" is not null', $relation->getQuery()->toSql());

        Price::addGlobalScope('test', function ($query) {
        });
    }

    public function testQualifyingSubSelectColumn()
    {
        $user = User::create();
        $this->assertSame('latest_login.id', $user->latest_login()->qualifySubSelectColumn('id'));
    }

    public function testItFailsWhenUsingInvalidAggregate()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid aggregate [count] used within ofMany relation. Available aggregates: MIN, MAX');
        $user = User::make();
        $user->latest_login_with_invalid_aggregate();
    }

    public function testItGetsCorrectResults()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $result = $user->latest_login()->getResults();
        $this->assertNotNull($result);
        $this->assertSame($latestLogin->id, $result->id);
    }

    public function testResultDoesNotHaveAggregateColumn()
    {
        $user = User::create();
        $user->logins()->create();

        $result = $user->latest_login()->getResults();
        $this->assertNotNull($result);
        $this->assertFalse(isset($result->id_aggregate));
    }

    public function testItGetsCorrectResultsUsingShortcutMethod()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $result = $user->latest_login_with_shortcut()->getResults();
        $this->assertNotNull($result);
        $this->assertSame($latestLogin->id, $result->id);
    }

    public function testItGetsCorrectResultsUsingShortcutReceivingMultipleColumnsMethod()
    {
        $user = User::create();
        $user->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);
        $price = $user->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);

        $result = $user->price_with_shortcut()->getResults();
        $this->assertNotNull($result);
        $this->assertSame($price->id, $result->id);
    }

    public function testKeyIsAddedToAggregatesWhenMissing()
    {
        $user = User::create();
        $user->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);
        $price = $user->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);

        $result = $user->price_without_key_in_aggregates()->getResults();
        $this->assertNotNull($result);
        $this->assertSame($price->id, $result->id);
    }

    public function testItGetsWithConstraintsCorrectResults()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $user->logins()->create();

        $result = $user->latest_login()->whereKey($previousLogin->getKey())->getResults();
        $this->assertNull($result);
    }

    public function testItEagerLoadsCorrectModels()
    {
        $user = User::create();
        $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $user = User::with('latest_login')->first();

        $this->assertTrue($user->relationLoaded('latest_login'));
        $this->assertSame($latestLogin->id, $user->latest_login->id);
    }

    public function testItJoinsOtherTableInSubQuery()
    {
        $user = User::create();
        $user->logins()->create();

        $this->assertNull($user->latest_login_with_foo_state);

        $user->unsetRelation('latest_login_with_foo_state');
        $user->states()->create([
            'type' => 'foo',
            'state' => 'draft',
        ]);

        $this->assertNotNull($user->latest_login_with_foo_state);
    }

    public function testHasNested()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $found = User::whereHas('latest_login', function ($query) use ($latestLogin) {
            $query->where('logins.id', $latestLogin->id);
        })->exists();
        $this->assertTrue($found);

        $found = User::whereHas('latest_login', function ($query) use ($previousLogin) {
            $query->where('logins.id', $previousLogin->id);
        })->exists();
        $this->assertFalse($found);
    }

    public function testWithHasNested()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $found = User::withWhereHas('latest_login', function ($query) use ($latestLogin) {
            $query->where('logins.id', $latestLogin->id);
        })->first();

        $this->assertTrue((bool) $found);
        $this->assertTrue($found->relationLoaded('latest_login'));
        $this->assertEquals($found->latest_login->id, $latestLogin->id);

        $found = User::withWhereHas('latest_login', function ($query) use ($previousLogin) {
            $query->where('logins.id', $previousLogin->id);
        })->exists();

        $this->assertFalse($found);
    }

    public function testHasCount()
    {
        $user = User::create();
        $user->logins()->create();
        $user->logins()->create();

        $user = User::withCount('latest_login')->first();
        $this->assertEquals(1, $user->latest_login_count);
    }

    public function testExists()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $this->assertFalse($user->latest_login()->whereKey($previousLogin->getKey())->exists());
        $this->assertTrue($user->latest_login()->whereKey($latestLogin->getKey())->exists());
    }

    public function testIsMethod()
    {
        $user = User::create();
        $login1 = $user->latest_login()->create();
        $login2 = $user->latest_login()->create();

        $this->assertFalse($user->latest_login()->is($login1));
        $this->assertTrue($user->latest_login()->is($login2));
    }

    public function testIsNotMethod()
    {
        $user = User::create();
        $login1 = $user->latest_login()->create();
        $login2 = $user->latest_login()->create();

        $this->assertTrue($user->latest_login()->isNot($login1));
        $this->assertFalse($user->latest_login()->isNot($login2));
    }

    public function testGet()
    {
        $user = User::create();
        $previousLogin = $user->logins()->create();
        $latestLogin = $user->logins()->create();

        $latestLogins = $user->latest_login()->get();
        $this->assertCount(1, $latestLogins);
        $this->assertSame($latestLogin->id, $latestLogins->first()->id);

        $latestLogins = $user->latest_login()->whereKey($previousLogin->getKey())->get();
        $this->assertCount(0, $latestLogins);
    }

    public function testCount()
    {
        $user = User::create();
        $user->logins()->create();
        $user->logins()->create();

        $this->assertSame(1, $user->latest_login()->count());
    }

    public function testAggregate()
    {
        $user = User::create();
        $firstLogin = $user->logins()->create();
        $user->logins()->create();

        $user = User::first();
        $this->assertSame($firstLogin->id, $user->first_login->id);
    }

    public function testJoinConstraints()
    {
        $user = User::create();
        $user->states()->create([
            'type' => 'foo',
            'state' => 'draft',
        ]);
        $currentForState = $user->states()->create([
            'type' => 'foo',
            'state' => 'active',
        ]);
        $user->states()->create([
            'type' => 'bar',
            'state' => 'baz',
        ]);

        $user = User::first();
        $this->assertSame($currentForState->id, $user->foo_state->id);
    }

    public function testMultipleAggregates()
    {
        $user = User::create();

        $user->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);
        $price = $user->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);

        $user = User::first();
        $this->assertSame($price->id, $user->price->id);
    }

    public function testEagerLoadingWithMultipleAggregates()
    {
        $user1 = User::create();
        $user2 = User::create();

        $user1->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);
        $user1Price = $user1->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);
        $user1->prices()->create([
            'published_at' => '2021-04-01 00:00:00',
        ]);

        $user2Price = $user2->prices()->create([
            'published_at' => '2021-05-01 00:00:00',
        ]);
        $user2->prices()->create([
            'published_at' => '2021-04-01 00:00:00',
        ]);

        $users = User::with('price')->get();

        $this->assertNotNull($users[0]->price);
        $this->assertSame($user1Price->id, $users[0]->price->id);

        $this->assertNotNull($users[1]->price);
        $this->assertSame($user2Price->id, $users[1]->price->id);
    }

    public function testWithExists()
    {
        $user = User::create();

        $user = User::withExists('latest_login')->first();
        $this->assertFalse($user->latest_login_exists);

        $user->logins()->create();
        $user = User::withExists('latest_login')->first();
        $this->assertTrue($user->latest_login_exists);
    }

    public function testWithExistsWithConstraintsInJoinSubSelect()
    {
        $user = User::create();

        $user = User::withExists('foo_state')->first();

        $this->assertFalse($user->foo_state_exists);

        $user->states()->create([
            'type' => 'foo',
            'state' => 'bar',
        ]);
        $user = User::withExists('foo_state')->first();
        $this->assertTrue($user->foo_state_exists);
    }

    public function testWithSoftDeletes()
    {
        $user = User::create();
        $user->logins()->create();
        $user->latest_login_with_soft_deletes;
        $this->assertNotNull($user->latest_login_with_soft_deletes);
    }

    public function testWithConstraintNotInAggregate()
    {
        $user = User::create();

        $previousFoo = $user->states()->create([
            'type' => 'foo',
            'state' => 'bar',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
        $newFoo = $user->states()->create([
            'type' => 'foo',
            'state' => 'active',
            'updated_at' => '2021-01-01 12:00:00',
        ]);
        $newBar = $user->states()->create([
            'type' => 'bar',
            'state' => 'active',
            'updated_at' => '2021-01-01 12:00:00',
        ]);

        $this->assertSame($newFoo->id, $user->last_updated_foo_state->id);
    }

    public function testItGetsCorrectResultUsingAtLeastTwoAggregatesDistinctFromId()
    {
        $user = User::create();

        $expectedState = $user->states()->create([
            'state' => 'state',
            'type' => 'type',
            'created_at' => '2023-01-01',
            'updated_at' => '2023-01-03',
        ]);

        $user->states()->create([
            'state' => 'state',
            'type' => 'type',
            'created_at' => '2023-01-01',
            'updated_at' => '2023-01-02',
        ]);

        $this->assertSame($user->latest_updated_latest_created_state->id, $expectedState->id);
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

    public bool $timestamps = false;

    public function logins()
    {
        return $this->hasMany(Login::class, 'user_id');
    }

    public function latest_login()
    {
        return $this->hasOne(Login::class, 'user_id')->ofMany();
    }

    public function latest_login_with_soft_deletes()
    {
        return $this->hasOne(LoginWithSoftDeletes::class, 'user_id')->ofMany();
    }

    public function latest_login_with_shortcut()
    {
        return $this->hasOne(Login::class, 'user_id')->latestOfMany();
    }

    public function latest_login_with_invalid_aggregate()
    {
        return $this->hasOne(Login::class, 'user_id')->ofMany('id', 'count');
    }

    public function latest_login_without_global_scope()
    {
        return $this->hasOne(Login::class, 'user_id')->withoutGlobalScopes()->latestOfMany();
    }

    public function first_login()
    {
        return $this->hasOne(Login::class, 'user_id')->ofMany('id', 'min');
    }

    public function latest_login_with_foo_state()
    {
        return $this->hasOne(Login::class, 'user_id')->ofMany(
            ['id' => 'max'],
            function ($query) {
                $query->join('states', 'states.user_id', 'logins.user_id')
                    ->where('states.type', 'foo');
            }
        );
    }

    public function states()
    {
        return $this->hasMany(State::class, 'user_id');
    }

    public function foo_state()
    {
        return $this->hasOne(State::class, 'user_id')->ofMany(
            [], // should automatically add 'id' => 'max'
            function ($q) {
                $q->where('type', 'foo');
            }
        );
    }

    public function last_updated_foo_state()
    {
        return $this->hasOne(State::class, 'user_id')->ofMany([
            'updated_at' => 'max',
            'id' => 'max',
        ], function ($q) {
            $q->where('type', 'foo');
        });
    }

    public function prices()
    {
        return $this->hasMany(Price::class, 'user_id');
    }

    public function price()
    {
        return $this->hasOne(Price::class, 'user_id')->ofMany([
            'published_at' => 'max',
            'id' => 'max',
        ], function ($q) {
            $q->where('published_at', '<', now());
        });
    }

    public function price_without_key_in_aggregates()
    {
        return $this->hasOne(Price::class, 'user_id')->ofMany(['published_at' => 'MAX']);
    }

    public function price_with_shortcut()
    {
        return $this->hasOne(Price::class, 'user_id')->latestOfMany(['published_at', 'id']);
    }

    public function price_without_global_scope()
    {
        return $this->hasOne(Price::class, 'user_id')->withoutGlobalScopes()->ofMany([
            'published_at' => 'max',
            'id' => 'max',
        ], function ($q) {
            $q->where('published_at', '<', now());
        });
    }

    public function latest_updated_latest_created_state()
    {
        return $this->hasOne(State::class, 'user_id')->ofMany([
            'updated_at' => 'max',
            'created_at' => 'max',
        ]);
    }
}

class TestModel extends Eloquent
{
    public function logins()
    {
        return $this->hasOne(Login::class)->ofMany();
    }
}

class Login extends Eloquent
{
    protected ?string $table = 'logins';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class LoginWithSoftDeletes extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'logins';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class State extends Eloquent
{
    protected ?string $table = 'states';

    protected array $guarded = [];

    public bool $timestamps = true;

    protected array $fillable = ['type', 'state', 'updated_at'];
}

class Price extends Eloquent
{
    protected ?string $table = 'prices';

    protected array $guarded = [];

    public bool $timestamps = false;

    protected array $fillable = ['published_at'];

    protected array $casts = ['published_at' => 'datetime'];
}
