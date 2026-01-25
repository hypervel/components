<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration\Eloquent;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Tests\Database\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class RelationsTest extends IntegrationTestCase
{
    public function testHasOneRelation(): void
    {
        $user = RelUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $profile = $user->profile()->create(['bio' => 'Hello world', 'avatar' => 'avatar.jpg']);

        $this->assertInstanceOf(RelProfile::class, $profile);
        $this->assertSame($user->id, $profile->user_id);

        $retrieved = RelUser::find($user->id);
        $this->assertInstanceOf(RelProfile::class, $retrieved->profile);
        $this->assertSame('Hello world', $retrieved->profile->bio);
    }

    public function testBelongsToRelation(): void
    {
        $user = RelUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $profile = $user->profile()->create(['bio' => 'Jane bio']);

        $retrieved = RelProfile::find($profile->id);
        $this->assertInstanceOf(RelUser::class, $retrieved->user);
        $this->assertSame('Jane', $retrieved->user->name);
    }

    public function testHasManyRelation(): void
    {
        $user = RelUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $user->posts()->create(['title' => 'Post 1', 'body' => 'Body 1']);
        $user->posts()->create(['title' => 'Post 2', 'body' => 'Body 2']);
        $user->posts()->create(['title' => 'Post 3', 'body' => 'Body 3']);

        $retrieved = RelUser::find($user->id);
        $this->assertCount(3, $retrieved->posts);
        $this->assertInstanceOf(Collection::class, $retrieved->posts);
        $this->assertInstanceOf(RelPost::class, $retrieved->posts->first());
    }

    public function testBelongsToManyRelation(): void
    {
        $user = RelUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = $user->posts()->create(['title' => 'Tagged Post', 'body' => 'Body']);

        $tag1 = RelTag::create(['name' => 'PHP']);
        $tag2 = RelTag::create(['name' => 'Laravel']);
        $tag3 = RelTag::create(['name' => 'Hypervel']);

        $post->tags()->attach([$tag1->id, $tag2->id, $tag3->id]);

        $retrieved = RelPost::find($post->id);
        $this->assertCount(3, $retrieved->tags);
        $this->assertContains('PHP', $retrieved->tags->pluck('name')->toArray());
    }

    public function testBelongsToManyWithPivot(): void
    {
        $user = RelUser::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $post = $user->posts()->create(['title' => 'Pivot Post', 'body' => 'Body']);

        $tag = RelTag::create(['name' => 'Testing']);
        $post->tags()->attach($tag->id);

        $retrieved = RelPost::find($post->id);
        $this->assertNotNull($retrieved->tags->first()->pivot);
        $this->assertSame($post->id, $retrieved->tags->first()->pivot->post_id);
        $this->assertSame($tag->id, $retrieved->tags->first()->pivot->tag_id);
    }

    public function testSyncRelation(): void
    {
        $user = RelUser::create(['name' => 'Dave', 'email' => 'dave@example.com']);
        $post = $user->posts()->create(['title' => 'Sync Post', 'body' => 'Body']);

        $tag1 = RelTag::create(['name' => 'Tag1']);
        $tag2 = RelTag::create(['name' => 'Tag2']);
        $tag3 = RelTag::create(['name' => 'Tag3']);

        $post->tags()->attach([$tag1->id, $tag2->id]);
        $this->assertCount(2, $post->fresh()->tags);

        $post->tags()->sync([$tag2->id, $tag3->id]);

        $retrieved = $post->fresh();
        $this->assertCount(2, $retrieved->tags);
        $this->assertContains('Tag2', $retrieved->tags->pluck('name')->toArray());
        $this->assertContains('Tag3', $retrieved->tags->pluck('name')->toArray());
        $this->assertNotContains('Tag1', $retrieved->tags->pluck('name')->toArray());
    }

    public function testDetachRelation(): void
    {
        $user = RelUser::create(['name' => 'Eve', 'email' => 'eve@example.com']);
        $post = $user->posts()->create(['title' => 'Detach Post', 'body' => 'Body']);

        $tag1 = RelTag::create(['name' => 'DetachTag1']);
        $tag2 = RelTag::create(['name' => 'DetachTag2']);

        $post->tags()->attach([$tag1->id, $tag2->id]);
        $this->assertCount(2, $post->fresh()->tags);

        $post->tags()->detach($tag1->id);
        $this->assertCount(1, $post->fresh()->tags);

        $post->tags()->detach();
        $this->assertCount(0, $post->fresh()->tags);
    }

    public function testMorphManyRelation(): void
    {
        $user = RelUser::create(['name' => 'Frank', 'email' => 'frank@example.com']);
        $post = $user->posts()->create(['title' => 'Morphed Post', 'body' => 'Body']);

        $post->comments()->create(['user_id' => $user->id, 'body' => 'Comment 1']);
        $post->comments()->create(['user_id' => $user->id, 'body' => 'Comment 2']);

        $retrieved = RelPost::find($post->id);
        $this->assertCount(2, $retrieved->comments);
        $this->assertInstanceOf(RelComment::class, $retrieved->comments->first());
    }

    public function testMorphToRelation(): void
    {
        $user = RelUser::create(['name' => 'Grace', 'email' => 'grace@example.com']);
        $post = $user->posts()->create(['title' => 'MorphTo Post', 'body' => 'Body']);
        $comment = $post->comments()->create(['user_id' => $user->id, 'body' => 'A comment']);

        $retrieved = RelComment::find($comment->id);
        $this->assertInstanceOf(RelPost::class, $retrieved->commentable);
        $this->assertSame($post->id, $retrieved->commentable->id);
    }

    public function testEagerLoadingWith(): void
    {
        $user = RelUser::create(['name' => 'Henry', 'email' => 'henry@example.com']);
        $user->profile()->create(['bio' => 'Henry bio']);
        $user->posts()->create(['title' => 'Post 1', 'body' => 'Body 1']);
        $user->posts()->create(['title' => 'Post 2', 'body' => 'Body 2']);

        $retrieved = RelUser::with(['profile', 'posts'])->find($user->id);

        $this->assertTrue($retrieved->relationLoaded('profile'));
        $this->assertTrue($retrieved->relationLoaded('posts'));
        $this->assertSame('Henry bio', $retrieved->profile->bio);
        $this->assertCount(2, $retrieved->posts);
    }

    public function testEagerLoadingWithCount(): void
    {
        $user = RelUser::create(['name' => 'Ivy', 'email' => 'ivy@example.com']);
        $user->posts()->create(['title' => 'Post 1', 'body' => 'Body 1']);
        $user->posts()->create(['title' => 'Post 2', 'body' => 'Body 2']);
        $user->posts()->create(['title' => 'Post 3', 'body' => 'Body 3']);

        $retrieved = RelUser::withCount('posts')->find($user->id);

        $this->assertSame(3, $retrieved->posts_count);
    }

    public function testNestedEagerLoading(): void
    {
        $user = RelUser::create(['name' => 'Jack', 'email' => 'jack@example.com']);
        $post = $user->posts()->create(['title' => 'Nested Post', 'body' => 'Body']);

        $tag = RelTag::create(['name' => 'Nested Tag']);
        $post->tags()->attach($tag->id);

        $retrieved = RelUser::with('posts.tags')->find($user->id);

        $this->assertTrue($retrieved->relationLoaded('posts'));
        $this->assertTrue($retrieved->posts->first()->relationLoaded('tags'));
        $this->assertSame('Nested Tag', $retrieved->posts->first()->tags->first()->name);
    }

    public function testHasQueryConstraint(): void
    {
        $user1 = RelUser::create(['name' => 'Kate', 'email' => 'kate@example.com']);
        $user2 = RelUser::create(['name' => 'Liam', 'email' => 'liam@example.com']);

        $user1->posts()->create(['title' => 'Kate Post', 'body' => 'Body']);

        $usersWithPosts = RelUser::has('posts')->get();

        $this->assertCount(1, $usersWithPosts);
        $this->assertSame('Kate', $usersWithPosts->first()->name);
    }

    public function testDoesntHaveQueryConstraint(): void
    {
        $user1 = RelUser::create(['name' => 'Mike', 'email' => 'mike@example.com']);
        $user2 = RelUser::create(['name' => 'Nancy', 'email' => 'nancy@example.com']);

        $user1->posts()->create(['title' => 'Mike Post', 'body' => 'Body']);

        $usersWithoutPosts = RelUser::doesntHave('posts')->get();

        $this->assertCount(1, $usersWithoutPosts);
        $this->assertSame('Nancy', $usersWithoutPosts->first()->name);
    }

    public function testWhereHasQueryConstraint(): void
    {
        $user1 = RelUser::create(['name' => 'Oscar', 'email' => 'oscar@example.com']);
        $user2 = RelUser::create(['name' => 'Paula', 'email' => 'paula@example.com']);

        $user1->posts()->create(['title' => 'PHP Tutorial', 'body' => 'Body']);
        $user2->posts()->create(['title' => 'JavaScript Guide', 'body' => 'Body']);

        $users = RelUser::whereHas('posts', function ($query) {
            $query->where('title', 'like', '%PHP%');
        })->get();

        $this->assertCount(1, $users);
        $this->assertSame('Oscar', $users->first()->name);
    }

    public function testSaveRelatedModel(): void
    {
        $user = RelUser::create(['name' => 'Quinn', 'email' => 'quinn@example.com']);

        $post = new RelPost(['title' => 'Saved Post', 'body' => 'Body']);
        $user->posts()->save($post);

        $this->assertTrue($post->exists);
        $this->assertSame($user->id, $post->user_id);
    }

    public function testSaveManyRelatedModels(): void
    {
        $user = RelUser::create(['name' => 'Rachel', 'email' => 'rachel@example.com']);

        $posts = [
            new RelPost(['title' => 'Post A', 'body' => 'Body A']),
            new RelPost(['title' => 'Post B', 'body' => 'Body B']),
        ];

        $user->posts()->saveMany($posts);

        $this->assertCount(2, $user->fresh()->posts);
    }

    public function testCreateManyRelatedModels(): void
    {
        $user = RelUser::create(['name' => 'Steve', 'email' => 'steve@example.com']);

        $user->posts()->createMany([
            ['title' => 'Created 1', 'body' => 'Body 1'],
            ['title' => 'Created 2', 'body' => 'Body 2'],
        ]);

        $this->assertCount(2, $user->fresh()->posts);
    }

    public function testAssociateBelongsTo(): void
    {
        $user = RelUser::create(['name' => 'Tom', 'email' => 'tom@example.com']);
        $post = RelPost::create(['user_id' => $user->id, 'title' => 'Initial', 'body' => 'Body']);

        $newUser = RelUser::create(['name' => 'Uma', 'email' => 'uma@example.com']);

        $post->user()->associate($newUser);
        $post->save();

        $this->assertSame($newUser->id, $post->fresh()->user_id);
    }

    public function testDissociateBelongsTo(): void
    {
        $user = RelUser::create(['name' => 'Victor', 'email' => 'victor@example.com']);
        $profile = $user->profile()->create(['bio' => 'Victor bio']);

        $profile->user()->dissociate();
        $profile->save();

        $this->assertNull($profile->fresh()->user_id);
    }
}

class RelUser extends Model
{
    protected ?string $table = 'rel_users';

    protected array $fillable = ['name', 'email'];

    public function profile(): HasOne
    {
        return $this->hasOne(RelProfile::class, 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(RelPost::class, 'user_id');
    }
}

class RelProfile extends Model
{
    protected ?string $table = 'rel_profiles';

    protected array $fillable = ['user_id', 'bio', 'avatar'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'user_id');
    }
}

class RelPost extends Model
{
    protected ?string $table = 'rel_posts';

    protected array $fillable = ['user_id', 'title', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelTag::class, 'rel_post_tag', 'post_id', 'tag_id')->withTimestamps();
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(RelComment::class, 'commentable');
    }
}

class RelTag extends Model
{
    protected ?string $table = 'rel_tags';

    protected array $fillable = ['name'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(RelPost::class, 'rel_post_tag', 'tag_id', 'post_id');
    }
}

class RelComment extends Model
{
    protected ?string $table = 'rel_comments';

    protected array $fillable = ['user_id', 'body'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'user_id');
    }
}
