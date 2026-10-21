<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentIntegrationWithTablePrefixTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Schema\Builder;
use Hypervel\Testbench\TestCase;

class DatabaseEloquentIntegrationWithTablePrefixTest extends TestCase
{
    /**
     * Bootstrap Eloquent.
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

        Eloquent::getConnectionResolver()->connection()->setTablePrefix('prefix_');

        $this->createSchema();
    }

    /**
     * Create the database schema.
     */
    protected function createSchema(): void
    {
        $this->schema('default')->create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->timestamps();
        });

        $this->schema('default')->create('friends', function ($table) {
            $table->integer('user_id');
            $table->integer('friend_id');
        });

        $this->schema('default')->create('posts', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('parent_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        $this->schema('default')->create('photos', function ($table) {
            $table->increments('id');
            $table->morphs('imageable');
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        foreach (['default'] as $connection) {
            $this->schema($connection)->drop('users');
            $this->schema($connection)->drop('friends');
            $this->schema($connection)->drop('posts');
            $this->schema($connection)->drop('photos');
        }

        Relation::morphMap([], false);

        parent::tearDown();
    }

    public function testBasicModelHydration(): void
    {
        User::create(['email' => 'taylorotwell@gmail.com']);
        User::create(['email' => 'abigailotwell@gmail.com']);

        $models = User::fromQuery('SELECT * FROM prefix_users WHERE email = ?', ['abigailotwell@gmail.com']);

        $this->assertInstanceOf(Collection::class, $models);
        $this->assertInstanceOf(User::class, $models[0]);
        $this->assertSame('abigailotwell@gmail.com', $models[0]->email);
        $this->assertCount(1, $models);
    }

    public function testTablePrefixWithClonedConnection(): void
    {
        $originalConnection = $this->connection();
        $originalPrefix = $originalConnection->getTablePrefix();

        $clonedConnection = clone $originalConnection;
        $clonedConnection->setTablePrefix('cloned_');

        $this->assertSame($originalPrefix, $originalConnection->getTablePrefix());
        $this->assertSame('cloned_', $clonedConnection->getTablePrefix());

        $clonedConnection->getSchemaBuilder()->create('test_table', function ($table) {
            $table->increments('id');
            $table->string('name');
        });

        $this->assertTrue($clonedConnection->getSchemaBuilder()->hasTable('test_table'));
        $query = $clonedConnection->table('test_table')->toSql();
        $this->assertStringContainsString('cloned_test_table', $query);

        $clonedConnection->getSchemaBuilder()->drop('test_table');
    }

    public function testQueryGrammarUsesCorrectPrefixAfterCloning(): void
    {
        $originalConnection = $this->connection();

        $clonedConnection = clone $originalConnection;
        $clonedConnection->setTablePrefix('new_prefix_');

        $selectSql = $clonedConnection->table('users')->toSql();
        $this->assertStringContainsString('new_prefix_users', $selectSql);

        $queries = $clonedConnection->pretend(function (Connection $connection): void {
            $connection->table('users')->insert(['email' => 'taylor@example.com']);
            $connection->table('users')->where('id', 1)->update(['email' => 'abigail@example.com']);
            $connection->table('users')->where('id', 1)->delete();
        });

        $this->assertSame([
            'insert into "new_prefix_users" ("email") values (\'taylor@example.com\')',
            'update "new_prefix_users" set "email" = \'abigail@example.com\' where "id" = 1',
            'delete from "new_prefix_users" where "id" = 1',
        ], array_column($queries, 'query'));

        $originalSql = $originalConnection->table('users')->toSql();
        $this->assertStringContainsString('prefix_users', $originalSql);
        $this->assertStringNotContainsString('new_prefix_users', $originalSql);
    }

    /**
     * Get a database connection instance.
     */
    protected function connection(string $connection = 'default'): Connection
    {
        return Eloquent::getConnectionResolver()->connection($connection);
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(string $connection = 'default'): Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }
}

class User extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];
}
