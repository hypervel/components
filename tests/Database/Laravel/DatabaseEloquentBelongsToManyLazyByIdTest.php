<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentBelongsToManyLazyByIdTest extends TestCase
{
    protected function setUp(): void
    {
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
        });

        $this->schema()->create('articles', function ($table) {
            $table->increments('aid');
            $table->string('title');
        });

        $this->schema()->create('article_user', function ($table) {
            $table->integer('article_id')->unsigned();
            $table->foreign('article_id')->references('aid')->on('articles');
            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function testBelongsToLazyById()
    {
        $this->seedData();

        $user = BelongsToManyLazyByIdTestTestUser::query()->first();
        $i = 0;

        $user->articles()->lazyById(1)->each(function ($model) use (&$i) {
            ++$i;
            $this->assertEquals($i, $model->aid);
        });

        $this->assertSame(3, $i);
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('articles');
        $this->schema()->drop('article_user');

        parent::tearDown();
    }

    /**
     * Helpers...
     */
    protected function seedData()
    {
        $user = BelongsToManyLazyByIdTestTestUser::create(['id' => 1, 'email' => 'taylorotwell@gmail.com']);
        BelongsToManyLazyByIdTestTestArticle::query()->insert([
            ['aid' => 1, 'title' => 'Another title'],
            ['aid' => 2, 'title' => 'Another title'],
            ['aid' => 3, 'title' => 'Another title'],
        ]);

        $user->articles()->sync([3, 1, 2]);
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\ConnectionInterface
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

class BelongsToManyLazyByIdTestTestUser extends Eloquent
{
    protected ?string $table = 'users';

    protected array $fillable = ['id', 'email'];

    public bool $timestamps = false;

    public function articles()
    {
        return $this->belongsToMany(BelongsToManyLazyByIdTestTestArticle::class, 'article_user', 'user_id', 'article_id');
    }
}

class BelongsToManyLazyByIdTestTestArticle extends Eloquent
{
    protected string $primaryKey = 'aid';

    protected ?string $table = 'articles';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $fillable = ['aid', 'title'];
}
