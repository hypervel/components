<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphLazyEagerLoadingTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Integration\Database\Fixtures\Models\MorphEagerLoading\User;

class EloquentMorphLazyEagerLoadingTest extends DatabaseTestCase
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

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $user = User::create();

        $post = tap((new Post)->user()->associate($user))->save();

        (new Comment)->commentable()->associate($post)->save();
    }

    public function testLazyEagerLoading(): void
    {
        $comment = Comment::first();

        $comment->loadMorph('commentable', [
            Post::class => ['user'],
        ]);

        $this->assertTrue($comment->relationLoaded('commentable'));
        $this->assertTrue($comment->commentable->relationLoaded('user'));
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    protected string $primaryKey = 'post_id';

    /**
     * Get the post's user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
