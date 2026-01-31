<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentThroughTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
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

        $post = tap(new Post(['public' => true]))->save();
        $comment = tap((new Comment())->commentable()->associate($post))->save();
        (new Like())->comment()->associate($comment)->save();
        (new Like())->comment()->associate($comment)->save();

        $otherCommentable = tap(new OtherCommentable())->save();
        $comment2 = tap((new Comment())->commentable()->associate($otherCommentable))->save();
        (new Like())->comment()->associate($comment2)->save();
    }

    public function test()
    {
        /** @var Post $post */
        $post = Post::first();
        $this->assertEquals(2, $post->commentLikes()->count());
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
}

class Like extends Model
{
    public bool $timestamps = false;

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
