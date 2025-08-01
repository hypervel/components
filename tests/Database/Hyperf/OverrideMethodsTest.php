<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf;

use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\Database\Model\Register;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\Relations\HasOneThrough;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\Database\Hyperf\Stubs\ContainerStub;
use Mockery;

/**
 * Test specifically for override methods that are not covered in other tests.
 * @internal
 * @coversNothing
 */
class OverrideMethodsTest extends \Hypervel\Testbench\TestCase
{
    use RunTestsInCoroutine;

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
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
        Schema::dropIfExists('countries');

        Mockery::close();
    }

    protected function createSchema(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('country_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('user_id');
        });
    }

    public function testModelNotFoundExceptionGettersWork()
    {
        $exception = new ModelNotFoundException();
        $exception->setModel('App\Models\User', [1, 2, 3]);

        $this->assertEquals('App\Models\User', $exception->getModel());
        $this->assertEquals([1, 2, 3], $exception->getIds());
    }

    public function testHasManyFindOrMethod()
    {
        // Create test data
        $user = OverrideTestUser::create(['id' => 1, 'name' => 'John', 'country_id' => 1]);
        $post1 = OverrideTestPost::create(['id' => 1, 'title' => 'Post 1', 'user_id' => 1]);
        $post2 = OverrideTestPost::create(['id' => 2, 'title' => 'Post 2', 'user_id' => 1]);

        // Test findOr with existing ID
        $result = $user->posts()->findOr(1, function () {
            return 'not found';
        });

        $this->assertInstanceOf(OverrideTestPost::class, $result);
        $this->assertEquals(1, $result->id);

        // Test findOr with non-existing ID
        $result = $user->posts()->findOr(999, function () {
            return 'not found';
        });

        $this->assertEquals('not found', $result);

        // Test findOr with multiple IDs
        $result = $user->posts()->findOr([1, 2], function () {
            return 'not found';
        });

        $this->assertCount(2, $result);
    }

    public function testHasManyFirstOrMethod()
    {
        // Create test data
        $user = OverrideTestUser::create(['id' => 1, 'name' => 'John', 'country_id' => 1]);
        $post = OverrideTestPost::create(['id' => 1, 'title' => 'Post 1', 'user_id' => 1]);

        // Test firstOr with existing record
        $result = $user->posts()->where('title', 'Post 1')->firstOr(function () {
            return 'not found';
        });

        $this->assertInstanceOf(OverrideTestPost::class, $result);
        $this->assertEquals('Post 1', $result->title);

        // Test firstOr with non-existing record
        $result = $user->posts()->where('title', 'Non-existent')->firstOr(function () {
            return 'not found';
        });

        $this->assertEquals('not found', $result);
    }

    public function testHasOneThroughFindOrMethod()
    {
        // Create test data
        $country = OverrideTestCountry::create(['id' => 1, 'name' => 'USA']);
        $user = OverrideTestUser::create(['id' => 1, 'name' => 'John', 'country_id' => 1]);
        $post = OverrideTestPost::create(['id' => 1, 'title' => 'Post 1', 'user_id' => 1]);

        // Test findOr with existing ID
        $result = $country->firstPost()->findOr(1, function () {
            return 'not found';
        });

        $this->assertInstanceOf(OverrideTestPost::class, $result);
        $this->assertEquals(1, $result->id);

        // Test findOr with non-existing ID
        $result = $country->firstPost()->findOr(999, function () {
            return 'not found';
        });

        $this->assertEquals('not found', $result);
    }

    public function testHasOneThroughFirstOrMethod()
    {
        // Create test data
        $country = OverrideTestCountry::create(['id' => 1, 'name' => 'USA']);
        $user = OverrideTestUser::create(['id' => 1, 'name' => 'John', 'country_id' => 1]);
        $post = OverrideTestPost::create(['id' => 1, 'title' => 'Post 1', 'user_id' => 1]);

        // Test firstOr with existing record
        $result = $country->firstPost()->where('title', 'Post 1')->firstOr(function () {
            return 'not found';
        });

        $this->assertInstanceOf(OverrideTestPost::class, $result);
        $this->assertEquals('Post 1', $result->title);

        // Test firstOr with non-existing record
        $result = $country->firstPost()->where('title', 'Non-existent')->firstOr(function () {
            return 'not found';
        });

        $this->assertEquals('not found', $result);
    }
}

class OverrideTestCountry extends Model
{
    protected ?string $table = 'countries';

    protected array $fillable = ['name'];

    public bool $timestamps = false;

    public function firstPost(): HasOneThrough
    {
        return $this->hasOneThrough(OverrideTestPost::class, OverrideTestUser::class, 'country_id', 'user_id');
    }
}

class OverrideTestUser extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['name', 'country_id'];

    public bool $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(OverrideTestPost::class, 'user_id');
    }
}

class OverrideTestPost extends Model
{
    protected ?string $table = 'posts';

    protected array $fillable = ['title', 'user_id'];

    public bool $timestamps = false;
}
