<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Tests\Database\Fixtures\Enums\Bar;
use Hypervel\Tests\TestCase;

class DatabaseEloquentBelongsToManyPivotEnumKeyTest extends TestCase
{
    /**
     * Set up the test environment.
     */
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

    /**
     * Create the test schema.
     */
    protected function createSchema(): void
    {
        $this->schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
        });

        $this->schema()->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        $this->schema()->create('role_user', function (Blueprint $table): void {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('role_id');
        });
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('roles');
        $this->schema()->drop('role_user');

        parent::tearDown();
    }

    public function testSyncAcceptsBackedEnumIds(): void
    {
        $user = PivotEnumKeyTestUser::create();
        PivotEnumKeyTestRole::insert([
            ['id' => 5, 'name' => 'editor'],
            ['id' => 6, 'name' => 'viewer'],
        ]);

        $changes = $user->roles()->sync([Bar::Foo, 6]);

        $this->assertSame([5, 6], $changes['attached']);
        $this->assertSame(
            ['editor', 'viewer'],
            $user->roles->pluck('name')->all()
        );
    }

    public function testToggleAcceptsBackedEnumIds(): void
    {
        $user = PivotEnumKeyTestUser::create();
        PivotEnumKeyTestRole::insert([
            ['id' => 5, 'name' => 'editor'],
        ]);

        $user->roles()->toggle([Bar::Foo]);

        $this->assertSame(['editor'], $user->fresh()->roles->pluck('name')->all());

        $user->roles()->toggle([Bar::Foo]);

        $this->assertSame([], $user->fresh()->roles->pluck('name')->all());
    }

    /**
     * Get the database connection.
     */
    protected function connection(): ConnectionInterface
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get the schema builder.
     */
    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class PivotEnumKeyTestUser extends Eloquent
{
    protected ?string $table = 'users';

    public bool $timestamps = false;

    /**
     * Get the roles belonging to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PivotEnumKeyTestRole::class, 'role_user', 'user_id', 'role_id');
    }
}

class PivotEnumKeyTestRole extends Eloquent
{
    protected ?string $table = 'roles';

    protected array $fillable = ['id', 'name'];

    public bool $timestamps = false;
}
