<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf;

use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Register;
use Hyperf\Database\Schema\Builder;
use Hyperf\DbConnection\Db;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Database\Hyperf\Stubs\ContainerStub;

/**
 * @internal
 * @coversNothing
 */
class DatabaseBelongsToManyEachByIdTest extends \Hypervel\Testbench\TestCase
{
    use \Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        $container = ContainerStub::getContainer();
        $connectionResolverInterface = $container->get(ConnectionResolverInterface::class);
        Register::setConnectionResolver($connectionResolverInterface);

        $db = new Db($container);
        $container->shouldReceive('get')->with(Db::class)->andReturn($db);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->schema()->drop('article_user');
        $this->schema()->drop('articles');
        $this->schema()->drop('users');
    }

    public function createSchema()
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
        });

        $this->schema()->create('articles', function ($table) {
            $table->increments('id');
            $table->string('title');
        });

        $this->schema()->create('article_user', function ($table) {
            $table->increments('id');
            $table->integer('article_id')->unsigned();
            $table->foreign('article_id')->references('id')->on('articles');
            $table->integer('user_id')->unsigned();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function testBelongsToEachById()
    {
        $this->seedData();

        $user = BelongsToManyEachByIdTestTestUser::query()->first();
        $i = 0;
        $user->articles()->eachById(function (BelongsToManyEachByIdTestTestArticle $model) use (&$i) {
            ++$i;
            $this->assertSame($i, $model->id);
            return true;
        }, 100, 'articles.id', 'id');

        $this->assertSame(3, $i);
    }

    protected function connection($connection = 'default'): Connection
    {
        return Register::getConnectionResolver()->connection($connection);
    }

    protected function schema($connection = 'default'): Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }

    /**
     * Helpers...
     */
    protected function seedData()
    {
        $user = BelongsToManyEachByIdTestTestUser::create(['id' => 1, 'email' => 'taylorotwell@gmail.com']);
        BelongsToManyEachByIdTestTestArticle::query()->insert([
            ['id' => 1, 'title' => 'Another title'],
            ['id' => 2, 'title' => 'Another title'],
            ['id' => 3, 'title' => 'Another title'],
        ]);

        $user->articles()->sync([1, 2, 3]);
    }
}

class BelongsToManyEachByIdTestTestUser extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'users';

    protected array $fillable = ['id', 'email'];

    public function articles()
    {
        return $this->belongsToMany(BelongsToManyEachByIdTestTestArticle::class, 'article_user', 'user_id', 'article_id');
    }
}

class BelongsToManyEachByIdTestTestArticle extends Model
{
    public bool $incrementing = false;

    public bool $timestamps = false;

    protected ?string $table = 'articles';

    protected string $keyType = 'string';

    protected array $fillable = ['id', 'title'];
}
