<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentMorphCountEagerLoadingTest;

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
class EloquentMorphCountEagerLoadingTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('likes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('post_id');
        });

        Schema::create('views', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('video_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $post = Post::create();
        $video = Video::create();

        tap((new Like())->post()->associate($post))->save();
        tap((new Like())->post()->associate($post))->save();

        tap((new View())->video()->associate($video))->save();

        (new Comment())->commentable()->associate($post)->save();
        (new Comment())->commentable()->associate($video)->save();
    }

    public function testWithMorphCountLoading()
    {
        $comments = Comment::query()
            ->with(['commentable' => function (MorphTo $morphTo) {
                $morphTo->morphWithCount([Post::class => ['likes']]);
            }])
            ->get();

        $this->assertTrue($comments[0]->relationLoaded('commentable'));
        $this->assertEquals(2, $comments[0]->commentable->likes_count);
        $this->assertTrue($comments[1]->relationLoaded('commentable'));
        $this->assertNull($comments[1]->commentable->views_count);
    }

    public function testWithMorphCountLoadingWithSingleRelation()
    {
        $comments = Comment::query()
            ->with(['commentable' => function (MorphTo $morphTo) {
                $morphTo->morphWithCount([Post::class => 'likes']);
            }])
            ->get();

        $this->assertTrue($comments[0]->relationLoaded('commentable'));
        $this->assertEquals(2, $comments[0]->commentable->likes_count);
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

class Video extends Model
{
    public bool $timestamps = false;

    public function views(): HasMany
    {
        return $this->hasMany(View::class);
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

class View extends Model
{
    public bool $timestamps = false;

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
