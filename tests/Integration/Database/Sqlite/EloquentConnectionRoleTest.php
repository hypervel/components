<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite\EloquentConnectionRoleTest;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Queue\SerializesAndRestoresModelIdentifiers;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class EloquentConnectionRoleTest extends TestCase
{
    protected string $directory;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $this->directory = ParallelTesting::tempDir('EloquentConnectionRoleTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->directory);
        $filesystem->ensureDirectoryExists($this->directory);

        foreach (['read', 'write'] as $role) {
            $pdo = new PDO('sqlite:' . $this->directory . '/' . $role . '.sqlite');
            $pdo->exec('create table users (id integer primary key, name text, role_id integer)');
            $pdo->exec("insert into users (id, name, role_id) values (1, '{$role}', 1)");
        }

        $app->make('config')->set('database.connections.roles', [
            'driver' => 'sqlite',
            'read' => ['database' => $this->directory . '/read.sqlite'],
            'write' => ['database' => $this->directory . '/write.sqlite'],
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'heartbeat_interval' => null,
                'idle_check_interval' => null,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }

    #[TestWith(['builder'])]
    #[TestWith(['save'])]
    #[TestWith(['saveOrIgnore'])]
    #[TestWith(['factory'])]
    public function testModelsKeepTheWriteConnectionAndItsTransaction(string $creation): void
    {
        $connection = DB::connection('roles::write');
        $failure = new RuntimeException('Roll back the model changes.');

        try {
            $connection->transaction(function () use ($connection, $creation, $failure): void {
                $model = DB::usingConnection('roles::write', function () use ($creation): User {
                    if ($creation === 'builder') {
                        return User::on('roles::write')->create(['name' => 'created']);
                    }

                    if ($creation === 'factory') {
                        return UserFactory::new()->create();
                    }

                    $model = new User(['name' => 'created']);
                    $this->assertTrue($model->{$creation}());

                    return $model;
                });

                $this->assertSame('roles::write', $model->getConnectionName());
                $this->assertSame($connection, $model->getConnection());
                $hydrated = User::on('roles::write')->findOrFail($model->id);
                $this->assertSame($connection, $hydrated->getConnection());
                $hydrated->update(['name' => 'updated']);
                $this->assertSame('updated', $connection->table('users')->find($model->id)->name);

                throw $failure;
            });
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $connection->table('users')->count());
    }

    public function testRelatedModelsAndPivotsKeepTheWriteConnection(): void
    {
        $connection = DB::connection('roles::write');
        $user = User::on('roles::write')->findOrFail(1);

        $this->assertSame($connection, $user->subject()->createModelByType(Role::class)->getConnection());
        $this->assertSame($connection, $user->roles()->newPivot()->getConnection());
        $this->assertSame($connection, $user->tags()->newPivot()->getConnection());
    }

    public function testModelReadFromReplicaSavesToPrimary(): void
    {
        $user = User::on('roles::read')->findOrFail(1);

        $this->assertSame('read', $user->name);
        $this->assertSame('roles', $user->getConnectionName());
        $user->update(['name' => 'updated']);

        $this->assertSame('updated', DB::connection('roles')->table('users')->useWritePdo()->find(1)->name);
        $this->assertSame('read', DB::connection('roles::read')->table('users')->find(1)->name);
    }

    public function testWriteAliasDoesNotChangeModelOrRelationshipIdentity(): void
    {
        $user = User::on('roles::write')->findOrFail(1);
        $plain = (new User)->setConnection('roles')->newFromBuilder(['id' => 1]);
        $read = (new User)->setConnection('roles::read')->newFromBuilder(['id' => 1]);
        $role = (new Role)->setConnection('roles')->newFromBuilder(['id' => 1]);

        $this->assertTrue($user->is($plain));
        $this->assertTrue($plain->is($user));
        $this->assertTrue($user->role()->is($role));
        $this->assertFalse($user->is($read));
        $this->assertFalse($user->role()->is($role->setConnection('roles::read')));
        $this->assertFalse($user->is($plain->setConnection('other')));
    }

    public function testQueueableNamesKeepReadRoutingAndNormalizeWriteAliases(): void
    {
        $write = User::on('roles::write')->findOrFail(1);
        $plain = (new User)->setConnection('roles')->newFromBuilder(['id' => 1]);
        $explicit = (new User)->setConnection('roles::write')->newFromBuilder(['id' => 1]);
        $read = (new User)->setConnection('roles::read')->newFromBuilder(['id' => 1]);

        $this->assertSame('roles', $write->getQueueableConnection());
        $this->assertSame('roles', $explicit->getQueueableConnection());
        $this->assertSame('roles', (new Collection([$write, $plain]))->getQueueableConnection());
        $this->assertSame('roles', (new Collection([$plain, $write]))->getQueueableConnection());
        $this->assertSame('roles::read', $read->getQueueableConnection());
        $this->assertSame('roles::read', (new Collection([$read]))->getQueueableConnection());

        $this->expectException(LogicException::class);
        (new Collection([$write, $read]))->getQueueableConnection();
    }

    #[TestWith(['read'])]
    #[TestWith(['write'])]
    public function testQueuedModelRestorationKeepsItsEndpoint(string $role): void
    {
        $model = (new User)->setConnection('roles::' . $role)->newFromBuilder(['id' => 1]);
        $restore = new class {
            use SerializesAndRestoresModelIdentifiers;
        };

        $restored = $restore->restoreModel(new ModelIdentifier(User::class, 1, [], $model->getQueueableConnection()));

        $this->assertSame($role, $restored->name);
    }
}

class User extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;

    /**
     * Get the user's role.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the user's subject.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user's roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Get the user's tags.
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'taggable');
    }
}

class Role extends Model
{
}

class UserFactory extends Factory
{
    protected ?string $model = User::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return ['name' => 'created'];
    }
}
