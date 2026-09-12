<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphToLazyEagerLoadingTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Integration\Database\Fixtures\Models\MorphEagerLoading\User;
use Hypervel\Tests\Integration\Database\Fixtures\Models\MorphEagerLoading\Video;

class EloquentMorphToLazyEagerLoadingTest extends DatabaseTestCase
{
    /**
     * Create the test tables and related models.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('post_id');
            $table->unsignedInteger('user_id');
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->increments('video_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $user = User::create();

        $post = tap((new Post)->user()->associate($user))->save();

        $video = Video::create();

        (new Comment)->commentable()->associate($post)->save();
        (new Comment)->commentable()->associate($video)->save();
    }

    public function testLazyEagerLoading(): void
    {
        $comments = Comment::all();

        DB::enableQueryLog();

        $comments->load('commentable');

        $this->assertCount(3, DB::getQueryLog());
        $this->assertTrue($comments[0]->relationLoaded('commentable'));
        $this->assertTrue($comments[0]->commentable->relationLoaded('user'));
        $this->assertTrue($comments[1]->relationLoaded('commentable'));
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    protected string $primaryKey = 'post_id';

    protected array $with = ['user'];

    /**
     * Get the post's user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
