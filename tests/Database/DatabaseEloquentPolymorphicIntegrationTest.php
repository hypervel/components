<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentPolymorphicIntegrationTest;

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

    public function testItLoadsRelationshipsAutomatically(): void
    {
        $this->seedData();

        $like = LikeWithSingleWith::first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->is(Comment::first()));
    }

    public function testItLoadsChainedRelationshipsAutomatically(): void
    {
        $this->seedData();

        $like = LikeWithSingleWith::first();

        $this->assertTrue($like->likeable->relationLoaded('commentable'));
        $this->assertTrue($like->likeable->commentable->is(Post::first()));
    }

    public function testItLoadsNestedRelationshipsAutomatically(): void
    {
        $this->seedData();

        $like = LikeWithNestedWith::first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->relationLoaded('owner'));

        $this->assertTrue($like->likeable->owner->is(User::first()));
    }

    public function testItLoadsNestedRelationshipsOnDemand(): void
    {
        $this->seedData();

        $like = Like::with('likeable.owner')->first();

        $this->assertTrue($like->relationLoaded('likeable'));
        $this->assertTrue($like->likeable->relationLoaded('owner'));

        $this->assertTrue($like->likeable->owner->is(User::first()));
    }

    public function testItLoadsNestedMorphRelationshipsOnDemand(): void
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

    public function testItLoadsNestedMorphRelationshipCountsOnDemand(): void
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

    public function testFindOrNewReturnsNewMorphModelWithMorphKeysSet(): void
    {
        $post = $this->createPost();

        $comment = $post->comments()->findOrNew(999);

        $this->assertFalse($comment->exists);
        $this->assertSame($post->id, $comment->commentable_id);
        $this->assertSame(Post::class, $comment->commentable_type);
    }

    public function testFindOrNewReturnsExistingMorphModel(): void
    {
        $post = $this->createPost();
        $existing = $post->comments()->create(['body' => 'foo', 'user_id' => 1]);

        $comment = $post->comments()->findOrNew($existing->id);

        $this->assertTrue($comment->exists);
        $this->assertSame($existing->id, $comment->id);
    }

    public function testFirstOrNewReturnsNewMorphModelWithMorphKeysSet(): void
    {
        $post = $this->createPost();

        $comment = $post->comments()->firstOrNew(['body' => 'foo'], ['user_id' => 1]);

        $this->assertFalse($comment->exists);
        $this->assertSame('foo', $comment->body);
        $this->assertSame(1, $comment->user_id);
        $this->assertSame($post->id, $comment->commentable_id);
        $this->assertSame(Post::class, $comment->commentable_type);
        $this->assertSame(0, DB::table('comments')->count());
    }

    public function testFirstOrCreateCreatesMorphModelWithMorphKeysSet(): void
    {
        $post = $this->createPost();

        $comment = $post->comments()->firstOrCreate(['body' => 'foo'], ['user_id' => 1]);

        $this->assertTrue($comment->wasRecentlyCreated);
        $this->assertMorphRow($post);

        $found = $post->comments()->firstOrCreate(['body' => 'foo'], ['user_id' => 2]);

        $this->assertFalse($found->wasRecentlyCreated);
        $this->assertSame(1, DB::table('comments')->count());
    }

    public function testFirstOrCreateIgnoresRowsOfAnotherMorphType(): void
    {
        $post = $this->createPost();
        DB::table('comments')->insert(['commentable_id' => $post->id, 'commentable_type' => 'other', 'body' => 'foo', 'user_id' => 9]);

        $comment = $post->comments()->firstOrCreate(['body' => 'foo'], ['user_id' => 1]);

        $this->assertTrue($comment->wasRecentlyCreated);
        $this->assertSame(Post::class, $comment->commentable_type);
        $this->assertSame(2, DB::table('comments')->count());
    }

    public function testCreateOrFirstCreatesMorphModelWithMorphKeysSet(): void
    {
        $post = $this->createPost();

        $comment = $post->comments()->createOrFirst(['body' => 'foo', 'user_id' => 1]);

        $this->assertTrue($comment->wasRecentlyCreated);
        $this->assertMorphRow($post);
    }

    public function testUpdateOrCreateCreatesMorphModelWithMorphKeysSet(): void
    {
        $post = $this->createPost();

        $comment = $post->comments()->updateOrCreate(['body' => 'foo'], ['user_id' => 1]);

        $this->assertTrue($comment->wasRecentlyCreated);
        $this->assertMorphRow($post);

        $updated = $post->comments()->updateOrCreate(['body' => 'foo'], ['user_id' => 2]);

        $this->assertFalse($updated->wasRecentlyCreated);
        $this->assertSame(1, DB::table('comments')->count());
        $this->assertSame(2, DB::table('comments')->value('user_id'));
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
     * Create a post to own polymorphic comments.
     */
    protected function createPost(): Post
    {
        return Post::create(['user_id' => 1, 'title' => 'Title', 'body' => 'Body']);
    }

    /**
     * Assert the single stored comment belongs to the given post.
     */
    protected function assertMorphRow(Post $post): void
    {
        $row = DB::table('comments')->first();

        $this->assertSame(1, DB::table('comments')->count());
        $this->assertSame($post->id, $row->commentable_id);
        $this->assertSame(Post::class, $row->commentable_type);
        $this->assertSame('foo', $row->body);
        $this->assertSame(1, $row->user_id);
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
