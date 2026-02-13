<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentIntegrationWithTablePrefixTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentIntegrationWithTablePrefixTest extends TestCase
{
    /**
     * Bootstrap Eloquent.
     */
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

        Eloquent::getConnectionResolver()->connection()->setTablePrefix('prefix_');

        $this->createSchema();
    }

    protected function createSchema()
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

    public function testBasicModelHydration()
    {
        User::create(['email' => 'taylorotwell@gmail.com']);
        User::create(['email' => 'abigailotwell@gmail.com']);

        $models = User::fromQuery('SELECT * FROM prefix_users WHERE email = ?', ['abigailotwell@gmail.com']);

        $this->assertInstanceOf(Collection::class, $models);
        $this->assertInstanceOf(User::class, $models[0]);
        $this->assertSame('abigailotwell@gmail.com', $models[0]->email);
        $this->assertCount(1, $models);
    }

    public function testTablePrefixWithClonedConnection()
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

    public function testQueryGrammarUsesCorrectPrefixAfterCloning()
    {
        $originalConnection = $this->connection();

        $clonedConnection = clone $originalConnection;
        $clonedConnection->setTablePrefix('new_prefix_');

        $selectSql = $clonedConnection->table('users')->toSql();
        $this->assertStringContainsString('new_prefix_users', $selectSql);

        $insertSql = $clonedConnection->table('users')->toSql();
        $this->assertStringContainsString('new_prefix_users', $insertSql);

        $updateSql = $clonedConnection->table('users')->where('id', 1)->toSql();
        $this->assertStringContainsString('new_prefix_users', $updateSql);

        $deleteSql = $clonedConnection->table('users')->where('id', 1)->toSql();
        $this->assertStringContainsString('new_prefix_users', $deleteSql);

        $originalSql = $originalConnection->table('users')->toSql();
        $this->assertStringContainsString('prefix_users', $originalSql);
        $this->assertStringNotContainsString('new_prefix_users', $originalSql);
    }

    /**
     * Helpers...
     * @param mixed $connection
     */

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function connection($connection = 'default')
    {
        return Eloquent::getConnectionResolver()->connection($connection);
    }

    /**
     * Get a schema builder instance.
     *
     * @param mixed $connection
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema($connection = 'default')
    {
        return $this->connection($connection)->getSchemaBuilder();
    }
}

class User extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];
}
