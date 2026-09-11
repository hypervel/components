<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphCountEagerLoadingTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Integration\Database\Fixtures\Models\PostLikes\Like;
use Hypervel\Tests\Integration\Database\Fixtures\Models\PostLikes\Post;

class EloquentMorphCountEagerLoadingTest extends DatabaseTestCase
{
    /**
     * Create the test tables and related models.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('likes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('post_id');
        });

        Schema::create('views', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('video_id');
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $post = Post::create();
        $video = Video::create();

        tap((new Like)->post()->associate($post))->save();
        tap((new Like)->post()->associate($post))->save();

        tap((new View)->video()->associate($video))->save();

        (new Comment)->commentable()->associate($post)->save();
        (new Comment)->commentable()->associate($video)->save();
    }

    public function testWithMorphCountLoading(): void
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

    public function testWithMorphCountLoadingWithSingleRelation(): void
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

class Video extends Model
{
    public bool $timestamps = false;

    /**
     * Get the video's views.
     */
    public function views(): HasMany
    {
        return $this->hasMany(View::class);
    }
}

class View extends Model
{
    public bool $timestamps = false;

    /**
     * Get the viewed video.
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
