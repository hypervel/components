<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Tests\TestCase;

class DatabaseEloquentPolymorphicIntegrationTest extends TestCase
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

    /**
     * Setup the database schema.
     */
    public function createSchema(): void
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $this->schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });

        $this->schema()->create('comments', function ($table) {
            $table->increments('id');
            $table->integer('commentable_id');
            $table->string('commentable_type');
            $table->integer('user_id');
            $table->text('body');
            $table->timestamps();
        });

        $this->schema()->create('likes', function ($table) {
            $table->increments('id');
            $table->integer('likeable_id');
            $table->string('likeable_type');
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
        $this->schema()->drop('comments');
        $this->schema()->drop('likes');

        parent::tearDown();
    }

    public function testItLoadsRelationshipsAutomatically()
    {
        $this->seedData();

        $like = TestLikeWithSingleWithPolymorphic::first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertEquals(TestCommentPolymorphic::first(), $like->likeable);
    }

    public function testItLoadsChainedRelationshipsAutomatically()
    {
        $this->seedData();

        $like = TestLikeWithSingleWithPolymorphic::first();

        $this->assertTrue($like->likeable->relationLoaded('commentable'));
        $this->assertEquals(TestPostPolymorphic::first(), $like->likeable->commentable);
    }

    public function testItLoadsNestedRelationshipsAutomatically()
    {
        $this->seedData();

        $like = TestLikeWithNestedWithPolymorphic::first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->relationLoaded('owner'));

        $this->assertEquals(TestUserPolymorphic::first(), $like->likeable->owner);
    }

    public function testItLoadsNestedRelationshipsOnDemand()
    {
        $this->seedData();

        $like = TestLikePolymorphic::with('likeable.owner')->first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->relationLoaded('owner'));

        $this->assertEquals(TestUserPolymorphic::first(), $like->likeable->owner);
    }

    public function testItLoadsNestedMorphRelationshipsOnDemand()
    {
        $this->seedData();

        TestPostPolymorphic::first()->likes()->create([]);

        $likes = TestLikePolymorphic::with('likeable.owner')->get()->loadMorph('likeable', [
            TestCommentPolymorphic::class => ['commentable'],
            TestPostPolymorphic::class => 'comments',
        ]);

        $this->assertTrue($likes[0]->relationLoaded('likeable'));
        $this->assertTrue($likes[0]->likeable->relationLoaded('owner'));
        $this->assertTrue($likes[0]->likeable->relationLoaded('commentable'));

        $this->assertTrue($likes[1]->relationLoaded('likeable'));
        $this->assertTrue($likes[1]->likeable->relationLoaded('owner'));
        $this->assertTrue($likes[1]->likeable->relationLoaded('comments'));
    }

    public function testItLoadsNestedMorphRelationshipCountsOnDemand()
    {
        $this->seedData();

        TestPostPolymorphic::first()->likes()->create([]);
        TestCommentPolymorphic::first()->likes()->create([]);

        $likes = TestLikePolymorphic::with('likeable.owner')->get()->loadMorphCount('likeable', [
            TestCommentPolymorphic::class => ['likes'],
            TestPostPolymorphic::class => 'comments',
        ]);

        $this->assertTrue($likes[0]->relationLoaded('likeable'));
        $this->assertTrue($likes[0]->likeable->relationLoaded('owner'));
        $this->assertEquals(2, $likes[0]->likeable->likes_count);

        $this->assertTrue($likes[1]->relationLoaded('likeable'));
        $this->assertTrue($likes[1]->likeable->relationLoaded('owner'));
        $this->assertEquals(1, $likes[1]->likeable->comments_count);

        $this->assertTrue($likes[2]->relationLoaded('likeable'));
        $this->assertTrue($likes[2]->likeable->relationLoaded('owner'));
        $this->assertEquals(2, $likes[2]->likeable->likes_count);
    }

    /**
     * Helpers...
     */
    protected function seedData(): void
    {
        $taylor = TestUserPolymorphic::create(['id' => 1, 'email' => 'taylorotwell@gmail.com']);

        $taylor->posts()->create(['title' => 'A title', 'body' => 'A body'])
            ->comments()->create(['body' => 'A comment body', 'user_id' => 1])
            ->likes()->create([]);
    }

    /**
     * Get a database connection instance.
     */
    protected function connection(): \Hypervel\Database\Connection
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(): \Hypervel\Database\Schema\Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}

/**
 * Eloquent Models...
 */
class TestUserPolymorphic extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(TestPostPolymorphic::class, 'user_id');
    }
}

class TestPostPolymorphic extends Eloquent
{
    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function comments()
    {
        return $this->morphMany(TestCommentPolymorphic::class, 'commentable');
    }

    public function owner()
    {
        return $this->belongsTo(TestUserPolymorphic::class, 'user_id');
    }

    public function likes()
    {
        return $this->morphMany(TestLikePolymorphic::class, 'likeable');
    }
}

class TestCommentPolymorphic extends Eloquent
{
    protected ?string $table = 'comments';

    protected array $guarded = [];

    protected array $with = ['commentable'];

    public function owner()
    {
        return $this->belongsTo(TestUserPolymorphic::class, 'user_id');
    }

    public function commentable()
    {
        return $this->morphTo();
    }

    public function likes()
    {
        return $this->morphMany(TestLikePolymorphic::class, 'likeable');
    }
}

class TestLikePolymorphic extends Eloquent
{
    protected ?string $table = 'likes';

    protected array $guarded = [];

    public function likeable()
    {
        return $this->morphTo();
    }
}

class TestLikeWithSingleWithPolymorphic extends Eloquent
{
    protected ?string $table = 'likes';

    protected array $guarded = [];

    protected array $with = ['likeable'];

    public function likeable()
    {
        return $this->morphTo();
    }
}

class TestLikeWithNestedWithPolymorphic extends Eloquent
{
    protected ?string $table = 'likes';

    protected array $guarded = [];

    protected array $with = ['likeable.owner'];

    public function likeable()
    {
        return $this->morphTo();
    }
}
