<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentHasManyThroughIntegrationTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentHasManyThroughIntegrationTest extends TestCase
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
            $table->string('email')->unique();
            $table->unsignedInteger('country_id');
            $table->string('country_short');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title');
            $table->text('body');
            $table->string('email');
            $table->timestamps();
        });

        $this->schema()->create('countries', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('shortname');
            $table->timestamps();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('posts');
        $this->schema()->drop('countries');

        parent::tearDown();
    }

    public function testItLoadsAHasManyThroughRelationWithCustomKeys()
    {
        $this->seedData();
        $posts = Country::first()->posts;

        $this->assertSame('A title', $posts[0]->title);
        $this->assertCount(2, $posts);
    }

    public function testItLoadsADefaultHasManyThroughRelation()
    {
        $this->migrateDefault();
        $this->seedDefaultData();

        $posts = DefaultCountry::first()->posts;
        $this->assertSame('A title', $posts[0]->title);
        $this->assertCount(2, $posts);

        $this->resetDefault();
    }

    public function testItLoadsARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $posts = IntermediateCountry::first()->posts;

        $this->assertSame('A title', $posts[0]->title);
        $this->assertCount(2, $posts);
    }

    public function testEagerLoadingARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $posts = IntermediateCountry::with('posts')->first()->posts;

        $this->assertSame('A title', $posts[0]->title);
        $this->assertCount(2, $posts);
    }

    public function testWhereHasOnARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $country = IntermediateCountry::whereHas('posts', function ($query) {
            $query->where('title', 'A title');
        })->get();

        $this->assertCount(1, $country);
    }

    public function testWithWhereHasOnARelationWithCustomIntermediateAndLocalKey()
    {
        $this->seedData();
        $country = IntermediateCountry::withWhereHas('posts', function ($query) {
            $query->where('title', 'A title');
        })->get();

        $this->assertCount(1, $country);
        $this->assertTrue($country->first()->relationLoaded('posts'));
        $this->assertEquals($country->first()->posts->pluck('title')->unique()->toArray(), ['A title']);
    }

    public function testFindMethod()
    {
        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->createMany([
                ['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com'],
                ['id' => 2, 'title' => 'Another title', 'body' => 'Another body', 'email' => 'taylorotwell@gmail.com'],
            ]);

        $country = Country::first();
        $post = $country->posts()->find(1);

        $this->assertNotNull($post);
        $this->assertSame('A title', $post->title);

        $this->assertCount(2, $country->posts()->find([1, 2]));
        $this->assertCount(2, $country->posts()->find(new Collection([1, 2])));
    }

    public function testFindManyMethod()
    {
        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->createMany([
                ['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com'],
                ['id' => 2, 'title' => 'Another title', 'body' => 'Another body', 'email' => 'taylorotwell@gmail.com'],
            ]);

        $country = Country::first();

        $this->assertCount(2, $country->posts()->findMany([1, 2]));
        $this->assertCount(2, $country->posts()->findMany(new Collection([1, 2])));
    }

    public function testFirstOrFailThrowsAnException()
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('No query results for model [Hypervel\Tests\Database\Laravel\DatabaseEloquentHasManyThroughIntegrationTest\Post].');

        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us']);

        Country::first()->posts()->firstOrFail();
    }

    public function testFindOrFailThrowsAnException()
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('No query results for model [Hypervel\Tests\Database\Laravel\DatabaseEloquentHasManyThroughIntegrationTest\Post] 1');

        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us']);

        Country::first()->posts()->findOrFail(1);
    }

    public function testFindOrFailWithManyThrowsAnException()
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('No query results for model [Hypervel\Tests\Database\Laravel\DatabaseEloquentHasManyThroughIntegrationTest\Post] 1, 2');

        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->create(['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com']);

        Country::first()->posts()->findOrFail([1, 2]);
    }

    public function testFindOrFailWithManyUsingCollectionThrowsAnException()
    {
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('No query results for model [Hypervel\Tests\Database\Laravel\DatabaseEloquentHasManyThroughIntegrationTest\Post] 1, 2');

        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->create(['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com']);

        Country::first()->posts()->findOrFail(new Collection([1, 2]));
    }

    public function testFindOrMethod()
    {
        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->create(['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com']);

        $result = Country::first()->posts()->findOr(1, fn () => 'callback result');
        $this->assertInstanceOf(Post::class, $result);
        $this->assertSame(1, $result->id);
        $this->assertSame('A title', $result->title);

        $result = Country::first()->posts()->findOr(1, ['posts.id'], fn () => 'callback result');
        $this->assertInstanceOf(Post::class, $result);
        $this->assertSame(1, $result->id);
        $this->assertNull($result->title);

        $result = Country::first()->posts()->findOr(2, fn () => 'callback result');
        $this->assertSame('callback result', $result);
    }

    public function testFindOrMethodWithMany()
    {
        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->createMany([
                ['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com'],
                ['id' => 2, 'title' => 'Another title', 'body' => 'Another body', 'email' => 'taylorotwell@gmail.com'],
            ]);

        $result = Country::first()->posts()->findOr([1, 2], fn () => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame(2, $result[1]->id);
        $this->assertSame('A title', $result[0]->title);
        $this->assertSame('Another title', $result[1]->title);

        $result = Country::first()->posts()->findOr([1, 2], ['posts.id'], fn () => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame(2, $result[1]->id);
        $this->assertNull($result[0]->title);
        $this->assertNull($result[1]->title);

        $result = Country::first()->posts()->findOr([1, 2, 3], fn () => 'callback result');
        $this->assertSame('callback result', $result);
    }

    public function testFindOrMethodWithManyUsingCollection()
    {
        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->createMany([
                ['id' => 1, 'title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com'],
                ['id' => 2, 'title' => 'Another title', 'body' => 'Another body', 'email' => 'taylorotwell@gmail.com'],
            ]);

        $result = Country::first()->posts()->findOr(new Collection([1, 2]), fn () => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame(2, $result[1]->id);
        $this->assertSame('A title', $result[0]->title);
        $this->assertSame('Another title', $result[1]->title);

        $result = Country::first()->posts()->findOr(new Collection([1, 2]), ['posts.id'], fn () => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame(2, $result[1]->id);
        $this->assertNull($result[0]->title);
        $this->assertNull($result[1]->title);

        $result = Country::first()->posts()->findOr(new Collection([1, 2, 3]), fn () => 'callback result');
        $this->assertSame('callback result', $result);
    }

    public function testFirstRetrievesFirstRecord()
    {
        $this->seedData();
        $post = Country::first()->posts()->first();

        $this->assertNotNull($post);
        $this->assertSame('A title', $post->title);
    }

    public function testAllColumnsAreRetrievedByDefault()
    {
        $this->seedData();
        $post = Country::first()->posts()->first();
        $this->assertEquals([
            'id',
            'user_id',
            'title',
            'body',
            'email',
            'created_at',
            'updated_at',
            'laravel_through_key',
        ], array_keys($post->getAttributes()));
    }

    public function testOnlyProperColumnsAreSelectedIfProvided()
    {
        $this->seedData();
        $post = Country::first()->posts()->first(['title', 'body']);

        $this->assertEquals([
            'title',
            'body',
            'laravel_through_key',
        ], array_keys($post->getAttributes()));
    }

    public function testChunkReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $country->posts()->chunk(10, function ($postsChunk) {
            $post = $postsChunk->first();
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key',
            ], array_keys($post->getAttributes()));
        });
    }

    public function testChunkById()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $i = 0;
        $count = 0;

        $country->posts()->chunkById(2, function ($collection) use (&$i, &$count) {
            ++$i;
            $count += $collection->count();
        });

        $this->assertEquals(3, $i);
        $this->assertEquals(6, $count);
    }

    public function testCursorReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $posts = $country->posts()->cursor();

        $this->assertInstanceOf(LazyCollection::class, $posts);

        foreach ($posts as $post) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key',
            ], array_keys($post->getAttributes()));
        }
    }

    public function testEachReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $country->posts()->each(function ($post) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key',
            ], array_keys($post->getAttributes()));
        });
    }

    public function testEachByIdReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $country->posts()->eachById(function ($post) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key',
            ], array_keys($post->getAttributes()));
        });
    }

    public function testLazyReturnsCorrectModels()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $country->posts()->lazy(10)->each(function ($post) {
            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key',
            ], array_keys($post->getAttributes()));
        });
    }

    public function testLazyById()
    {
        $this->seedData();
        $this->seedDataExtended();
        $country = Country::find(2);

        $i = 0;

        $country->posts()->lazyById(2)->each(function ($post) use (&$i, &$count) {
            ++$i;

            $this->assertEquals([
                'id',
                'user_id',
                'title',
                'body',
                'email',
                'created_at',
                'updated_at',
                'laravel_through_key',
            ], array_keys($post->getAttributes()));
        });

        $this->assertEquals(6, $i);
    }

    public function testIntermediateSoftDeletesAreIgnored()
    {
        $this->seedData();
        SoftDeletesUser::first()->delete();

        $posts = SoftDeletesCountry::first()->posts;

        $this->assertSame('A title', $posts[0]->title);
        $this->assertCount(2, $posts);
    }

    public function testEagerLoadingLoadsRelatedModelsCorrectly()
    {
        $this->seedData();
        $country = SoftDeletesCountry::with('posts')->first();

        $this->assertSame('us', $country->shortname);
        $this->assertSame('A title', $country->posts[0]->title);
        $this->assertCount(2, $country->posts);
    }

    /**
     * Helpers...
     */
    protected function seedData()
    {
        Country::create(['id' => 1, 'name' => 'United States of America', 'shortname' => 'us'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'country_short' => 'us'])
            ->posts()->createMany([
                ['title' => 'A title', 'body' => 'A body', 'email' => 'taylorotwell@gmail.com'],
                ['title' => 'Another title', 'body' => 'Another body', 'email' => 'taylorotwell@gmail.com'],
            ]);
    }

    protected function seedDataExtended()
    {
        $country = Country::create(['id' => 2, 'name' => 'United Kingdom', 'shortname' => 'uk']);
        $country->users()->create(['id' => 2, 'email' => 'example1@gmail.com', 'country_short' => 'uk'])
            ->posts()->createMany([
                ['title' => 'Example1 title1', 'body' => 'Example1 body1', 'email' => 'example1post1@gmail.com'],
                ['title' => 'Example1 title2', 'body' => 'Example1 body2', 'email' => 'example1post2@gmail.com'],
            ]);
        $country->users()->create(['id' => 3, 'email' => 'example2@gmail.com', 'country_short' => 'uk'])
            ->posts()->createMany([
                ['title' => 'Example2 title1', 'body' => 'Example2 body1', 'email' => 'example2post1@gmail.com'],
                ['title' => 'Example2 title2', 'body' => 'Example2 body2', 'email' => 'example2post2@gmail.com'],
            ]);
        $country->users()->create(['id' => 4, 'email' => 'example3@gmail.com', 'country_short' => 'uk'])
            ->posts()->createMany([
                ['title' => 'Example3 title1', 'body' => 'Example3 body1', 'email' => 'example3post1@gmail.com'],
                ['title' => 'Example3 title2', 'body' => 'Example3 body2', 'email' => 'example3post2@gmail.com'],
            ]);
    }

    /**
     * Seed data for a default HasManyThrough setup.
     */
    protected function seedDefaultData()
    {
        DefaultCountry::create(['id' => 1, 'name' => 'United States of America'])
            ->users()->create(['id' => 1, 'email' => 'taylorotwell@gmail.com'])
            ->posts()->createMany([
                ['title' => 'A title', 'body' => 'A body'],
                ['title' => 'Another title', 'body' => 'Another body'],
            ]);
    }

    /**
     * Drop the default tables.
     */
    protected function resetDefault()
    {
        $this->schema()->drop('users_default');
        $this->schema()->drop('posts_default');
        $this->schema()->drop('countries_default');
    }

    /**
     * Migrate tables for classes with a Laravel "default" HasManyThrough setup.
     */
    protected function migrateDefault()
    {
        $this->schema()->create('users_default', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->unsignedInteger('default_country_id');
            $table->timestamps();
        });

        $this->schema()->create('posts_default', function ($table) {
            $table->increments('id');
            $table->integer('default_user_id');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        $this->schema()->create('countries_default', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
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

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

/**
 * Eloquent Models...
 */
class Post extends Eloquent
{
    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

class Country extends Eloquent
{
    protected ?string $table = 'countries';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasManyThrough(Post::class, User::class, 'country_id', 'user_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'country_id');
    }
}

/**
 * Eloquent Models...
 */
class DefaultUser extends Eloquent
{
    protected ?string $table = 'users_default';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(DefaultPost::class);
    }
}

/**
 * Eloquent Models...
 */
class DefaultPost extends Eloquent
{
    protected ?string $table = 'posts_default';

    protected array $guarded = [];

    public function owner()
    {
        return $this->belongsTo(DefaultUser::class);
    }
}

class DefaultCountry extends Eloquent
{
    protected ?string $table = 'countries_default';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasManyThrough(DefaultPost::class, DefaultUser::class);
    }

    public function users()
    {
        return $this->hasMany(DefaultUser::class);
    }
}

class IntermediateCountry extends Eloquent
{
    protected ?string $table = 'countries';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasManyThrough(Post::class, User::class, 'country_short', 'email', 'shortname', 'email');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'country_id');
    }
}

class SoftDeletesUser extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'users';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(SoftDeletesPost::class, 'user_id');
    }
}

/**
 * Eloquent Models...
 */
class SoftDeletesPost extends Eloquent
{
    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function owner()
    {
        return $this->belongsTo(SoftDeletesUser::class, 'user_id');
    }
}

class SoftDeletesCountry extends Eloquent
{
    protected ?string $table = 'countries';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasManyThrough(SoftDeletesPost::class, User::class, 'country_id', 'user_id');
    }

    public function users()
    {
        return $this->hasMany(SoftDeletesUser::class, 'country_id');
    }
}
