<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentMorphCountLazyEagerLoadingTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentMorphCountLazyEagerLoadingTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('likes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('post_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $post = Post::create();

        tap((new Like())->post()->associate($post))->save();
        tap((new Like())->post()->associate($post))->save();

        (new Comment())->commentable()->associate($post)->save();
    }

    public function testLazyEagerLoading()
    {
        $comment = Comment::first();

        $comment->loadMorphCount('commentable', [
            Post::class => ['likes'],
        ]);

        $this->assertTrue($comment->relationLoaded('commentable'));
        $this->assertEquals(2, $comment->commentable->likes_count);
    }
}

class Comment extends Model
{
    public bool $timestamps = false;

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }
}

class Like extends Model
{
    public bool $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
