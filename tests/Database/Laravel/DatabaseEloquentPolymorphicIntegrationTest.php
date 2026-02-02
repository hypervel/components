<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentPolymorphicIntegrationTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentPolymorphicIntegrationTest extends TestCase
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

        $like = LikeWithSingleWith::first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertEquals(Comment::first(), $like->likeable);
    }

    public function testItLoadsChainedRelationshipsAutomatically()
    {
        $this->seedData();

        $like = LikeWithSingleWith::first();

        $this->assertTrue($like->likeable->relationLoaded('commentable'));
        $this->assertEquals(Post::first(), $like->likeable->commentable);
    }

    public function testItLoadsNestedRelationshipsAutomatically()
    {
        $this->seedData();

        $like = LikeWithNestedWith::first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->relationLoaded('owner'));

        $this->assertEquals(User::first(), $like->likeable->owner);
    }

    public function testItLoadsNestedRelationshipsOnDemand()
    {
        $this->seedData();

        $like = Like::with('likeable.owner')->first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->relationLoaded('owner'));

        $this->assertEquals(User::first(), $like->likeable->owner);
    }

    public function testItLoadsNestedMorphRelationshipsOnDemand()
    {
        $this->seedData();

        Post::first()->likes()->create([]);

        $likes = Like::with('likeable.owner')->get()->loadMorph('likeable', [
            Comment::class => ['commentable'],
            Post::class => 'comments',
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

        Post::first()->likes()->create([]);
        Comment::first()->likes()->create([]);

        $likes = Like::with('likeable.owner')->get()->loadMorphCount('likeable', [
            Comment::class => ['likes'],
            Post::class => 'comments',
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
        $taylor = User::create(['id' => 1, 'email' => 'taylorotwell@gmail.com']);

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
class User extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

class Post extends Eloquent
{
    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }
}

class Comment extends Eloquent
{
    protected ?string $table = 'comments';

    protected array $guarded = [];

    protected array $with = ['commentable'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commentable()
    {
        return $this->morphTo();
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }
}

class Like extends Eloquent
{
    protected ?string $table = 'likes';

    protected array $guarded = [];

    public function likeable()
    {
        return $this->morphTo();
    }
}

class LikeWithSingleWith extends Eloquent
{
    protected ?string $table = 'likes';

    protected array $guarded = [];

    protected array $with = ['likeable'];

    public function likeable()
    {
        return $this->morphTo();
    }
}

class LikeWithNestedWith extends Eloquent
{
    protected ?string $table = 'likes';

    protected array $guarded = [];

    protected array $with = ['likeable.owner'];

    public function likeable()
    {
        return $this->morphTo();
    }
}
