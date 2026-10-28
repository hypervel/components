<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentThroughTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentThroughTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('public');
        });

        Schema::create('other_commentables', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('comment_id');
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('comment_id');
            $table->integer('rank')->default(0);
        });

        Schema::create('texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
        });

        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->morphs('imageable');
        });

        $post = tap(new Post(['public' => true]))->save();
        $comment = tap((new Comment)->commentable()->associate($post))->save();
        (new Like)->comment()->associate($comment)->save();
        (new Like)->comment()->associate($comment)->save();

        $otherCommentable = tap(new OtherCommentable)->save();
        $comment2 = tap((new Comment)->commentable()->associate($otherCommentable))->save();
        (new Like)->comment()->associate($comment2)->save();
    }

    public function test(): void
    {
        /** @var Post $post */
        $post = Post::first();
        $this->assertEquals(2, $post->commentLikes()->count());
    }

    public function testMorphManyLocalRelationshipReturnsHasManyThrough(): void
    {
        /** @var Post $post */
        $post = Post::first();
        tap((new Comment)->commentable()->associate($post))->save();
        $post->comments->each(fn ($comment) => $comment->profile()->create());

        $this->assertInstanceOf(HasManyThrough::class, $post->commentProfiles());
        $this->assertCount(2, $post->commentProfiles);
    }

    public function testDistantMorphManyExcludesOtherMorphTypes(): void
    {
        $post = Post::firstOrFail();
        $text = $post->texts()->create();
        $images = $text->images()->createMany([[], []]);
        Image::create(['imageable_id' => $text->getKey(), 'imageable_type' => Post::class]);

        $relation = $post->through($post->texts()->one())->has('images');

        $this->assertInstanceOf(HasManyThrough::class, $relation);
        $this->assertEqualsCanonicalizing($images->modelKeys(), $relation->get()->modelKeys());
    }

    public function testDistantMorphOneExcludesOtherMorphTypes(): void
    {
        $post = Post::firstOrFail();
        $text = $post->texts()->create();
        Image::create(['imageable_id' => $text->getKey(), 'imageable_type' => Post::class]);
        $image = $text->image()->create();

        $relation = $post->through($post->texts()->one())->has('image');

        $this->assertInstanceOf(HasOneThrough::class, $relation);
        $this->assertTrue($image->is($relation->getResults()));
    }

    public function testOneOfManyKeepsMorphConstraintsInEveryAggregate(): void
    {
        $post = Post::firstOrFail();
        $profile = $post->comments()->firstOrFail()->profile()->create(['rank' => 10]);
        OtherCommentable::firstOrFail()->comments()->firstOrFail()->profile()->create(['rank' => 10]);

        $relation = $post->commentProfiles()->one()->ofMany(['rank' => 'MAX', 'id' => 'MAX']);

        $this->assertTrue($profile->is($relation->getResults()));
    }
}

class Comment extends Model
{
    public bool $timestamps = false;

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    /**
     * Get the comment's profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $withCount = ['comments'];

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function commentLikes(): HasManyThrough
    {
        return $this->through($this->comments())->has('likes');
    }

    /**
     * Get profiles through the post's comments.
     */
    public function commentProfiles(): HasManyThrough
    {
        return $this->through($this->comments())->has('profile');
    }

    public function texts(): HasMany
    {
        return $this->hasMany(Text::class);
    }
}

class OtherCommentable extends Model
{
    public bool $timestamps = false;

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}

class Text extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the text's images.
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * Get the text's image.
     */
    public function image(): MorphOne
    {
        return $this->images()->one();
    }
}

class Like extends Model
{
    public bool $timestamps = false;

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}

class Profile extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}

class Image extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}
