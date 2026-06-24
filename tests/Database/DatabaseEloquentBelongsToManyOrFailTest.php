<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBelongsToManyOrFailTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Schema\Builder;
use Hypervel\Tests\TestCase;
use RuntimeException;

class DatabaseEloquentBelongsToManyOrFailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    public function createSchema(): void
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
        });

        $this->schema()->create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
        });

        $this->schema()->create('role_user', function ($table) {
            $table->integer('user_id')->unsigned();
            $table->integer('role_id')->unsigned();
            $table->boolean('active')->default(false);
        });
    }

    protected function tearDown(): void
    {
        $this->schema()->drop('role_user');
        $this->schema()->drop('roles');
        $this->schema()->drop('users');

        parent::tearDown();
    }

    protected function seedData(): void
    {
        OrFailUser::create(['id' => 1, 'email' => 'taylor@hypervel.org']);
        OrFailRole::insert([
            ['id' => 1, 'name' => 'Admin'],
            ['id' => 2, 'name' => 'Editor'],
            ['id' => 3, 'name' => 'Viewer'],
        ]);
    }

    public function testSyncOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);

        $result = $user->roles()->syncOrFail([1, 2]);

        $this->assertSame([1, 2], $result['attached']);
        $this->assertEmpty($result['detached']);
        $this->assertEmpty($result['updated']);
        $this->assertCount(2, $user->roles);
    }

    public function testSyncWithoutDetachingOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);
        $user->roles()->attach([1]);

        $result = $user->roles()->syncWithoutDetachingOrFail([2, 3]);

        $this->assertSame([2, 3], $result['attached']);
        $this->assertEmpty($result['detached']);
        $this->assertCount(3, $user->roles()->get());
    }

    public function testAttachOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);

        $user->roles()->attachOrFail(1);

        $this->assertCount(1, $user->roles);
    }

    public function testAttachOrFailWithAttributes(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);
        $user->roles()->attachOrFail(1, ['active' => true]);

        $pivot = DB::table('role_user')->where('user_id', 1)->where('role_id', 1)->first();
        $this->assertSame(1, $pivot->active);
    }

    public function testDetachOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);
        $user->roles()->attach([1, 2, 3]);

        $result = $user->roles()->detachOrFail([1, 2]);

        $this->assertSame(2, $result);
        $this->assertCount(1, $user->roles()->get());
    }

    public function testDetachOrFailAll(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);
        $user->roles()->attach([1, 2, 3]);

        $result = $user->roles()->detachOrFail();

        $this->assertSame(3, $result);
        $this->assertCount(0, $user->roles()->get());
    }

    public function testToggleOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);
        $user->roles()->attach([1]);

        $result = $user->roles()->toggleOrFail([1, 2]);

        $this->assertSame([1], $result['detached']);
        $this->assertSame([2], $result['attached']);
        $this->assertCount(1, $user->roles()->get());
    }

    public function testSyncWithPivotValuesOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);

        $result = $user->roles()->syncWithPivotValuesOrFail([1, 2], ['active' => true]);

        $this->assertSame([1, 2], $result['attached']);
        $this->assertEmpty($result['detached']);
        $this->assertEmpty($result['updated']);

        $pivot = DB::table('role_user')->where('user_id', 1)->where('role_id', 1)->first();
        $this->assertSame(1, $pivot->active);
    }

    public function testUpdateExistingPivotOrFail(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);
        $user->roles()->attach(1, ['active' => false]);

        $result = $user->roles()->updateExistingPivotOrFail(1, ['active' => true]);

        $this->assertSame(1, $result);

        $pivot = DB::table('role_user')->where('user_id', 1)->where('role_id', 1)->first();
        $this->assertSame(1, $pivot->active);
    }

    public function testAttachOrFailRollsBackWhenCustomPivotSaveFails(): void
    {
        $this->seedData();

        $user = OrFailUser::find(1);

        try {
            $user->rolesWithFailingPivot()->attachOrFail(1);

            $this->fail('Expected the failing pivot save to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Pivot save failed.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('role_user')->count());
    }

    /**
     * Get a database connection instance.
     */
    protected function connection(): Connection
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class OrFailUser extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public bool $timestamps = false;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(OrFailRole::class, 'role_user', 'user_id', 'role_id');
    }

    public function rolesWithFailingPivot(): BelongsToMany
    {
        return $this->belongsToMany(OrFailRole::class, 'role_user', 'user_id', 'role_id')
            ->using(OrFailFailingPivot::class);
    }
}

class OrFailRole extends Eloquent
{
    protected ?string $table = 'roles';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class OrFailFailingPivot extends Pivot
{
    protected ?string $table = 'role_user';

    public bool $timestamps = false;

    public function save(array $options = []): bool
    {
        parent::save($options);

        throw new RuntimeException('Pivot save failed.');
    }
}
