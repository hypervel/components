<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Eloquent\SoftDeletingScope;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

class DatabaseSoftDeletingScopeTest extends TestCase
{
    public function testApplyingScopeToABuilder(): void
    {
        $builder = $this->newBuilder(scoped: false);

        (new SoftDeletingScope)->apply($builder, $builder->getModel());

        $this->assertSame('select * from "users" where "users"."deleted_at" is null', $builder->toSql());
    }

    public function testRestoreExtension(): void
    {
        $connection = $this->newConnection();
        $builder = $this->newBuilder($connection);

        $builder->restore();

        $this->assertSame(0, $connection->table('users')->whereNotNull('deleted_at')->count());
    }

    public function testRestoreOrCreateExtension(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $builder = new EloquentBuilder(new BaseBuilder(
            $connection,
            new Grammar($connection),
            new Processor
        ));

        $scope = new SoftDeletingScope;
        $scope->extend($builder);
        $callback = $builder->getMacro('restoreOrCreate');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('withTrashed');
        $attributes = ['name' => 'foo'];
        $values = ['email' => 'bar'];
        $model = m::mock(Model::class);
        $givenBuilder->expects('firstOrCreate')->with($attributes, $values)->andReturn($model);
        $model->expects('restore')->andReturn(true);
        $result = $callback($givenBuilder, $attributes, $values);

        $this->assertEquals($model, $result);
    }

    public function testCreateOrRestoreExtension(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $builder = new EloquentBuilder(new BaseBuilder(
            $connection,
            new Grammar($connection),
            new Processor
        ));

        $scope = new SoftDeletingScope;
        $scope->extend($builder);
        $callback = $builder->getMacro('createOrRestore');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('withTrashed');
        $attributes = ['name' => 'foo'];
        $values = ['email' => 'bar'];
        $model = m::mock(Model::class);
        $givenBuilder->expects('createOrFirst')->with($attributes, $values)->andReturn($model);
        $model->expects('restore')->andReturn(true);
        $result = $callback($givenBuilder, $attributes, $values);

        $this->assertEquals($model, $result);
    }

    public function testWithTrashedExtension(): void
    {
        $builder = $this->newBuilder();

        // Hypervel always groups the constraints added by a global scope.
        $this->assertSame('select * from "users" where ("users"."deleted_at" is null)', $builder->toSql());
        $this->assertSame('select * from "users"', $builder->withTrashed()->toSql());
    }

    public function testOnlyTrashedExtension(): void
    {
        $builder = $this->newBuilder();

        $this->assertSame('select * from "users" where "users"."deleted_at" is not null', $builder->onlyTrashed()->toSql());
    }

    public function testWithoutTrashedExtension(): void
    {
        $builder = $this->newBuilder();

        $this->assertSame('select * from "users" where "users"."deleted_at" is null', $builder->withoutTrashed()->toSql());
    }

    /**
     * Create an in-memory connection with one trashed and one active user.
     */
    protected function newConnection(): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('create table "users" ("id" integer primary key, "deleted_at" text, "updated_at" text)');
        $pdo->exec("insert into \"users\" values (1, '2023-01-01 00:00:00', null)");
        $pdo->exec('insert into "users" values (2, null, null)');

        return new SQLiteConnection($pdo);
    }

    /**
     * Create a soft deleting model builder, optionally with the scope and its extensions applied.
     */
    protected function newBuilder(?SQLiteConnection $connection = null, bool $scoped = true): EloquentBuilder
    {
        $builder = (new EloquentBuilder(($connection ?? new SQLiteConnection(new PDO('sqlite::memory:')))->query()))
            ->setModel(new SoftDeletingScopeModelStub);

        if ($scoped) {
            $scope = new SoftDeletingScope;
            $builder->withGlobalScope(SoftDeletingScope::class, $scope);
            $scope->extend($builder);
        }

        return $builder;
    }
}

class SoftDeletingScopeModelStub extends Model
{
    use SoftDeletes;

    protected ?string $table = 'users';

    protected ?string $dateFormat = 'Y-m-d H:i:s';
}
