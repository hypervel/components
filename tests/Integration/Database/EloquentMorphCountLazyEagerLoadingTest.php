<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphCountLazyEagerLoadingTest;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Database\Fixtures\Models\PostLikes\Like;
use Hypervel\Tests\Database\Fixtures\Models\PostLikes\Post;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentMorphCountLazyEagerLoadingTest extends DatabaseTestCase
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

        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $post = Post::create();

        tap((new Like)->post()->associate($post))->save();
        tap((new Like)->post()->associate($post))->save();

        (new Comment)->commentable()->associate($post)->save();
    }

    public function testLazyEagerLoading(): void
    {
        $comment = Comment::first();

        $comment->loadMorphCount('commentable', [
            Post::class => ['likes'],
        ]);

        $this->assertTrue($comment->relationLoaded('commentable'));
        $this->assertEquals(2, $comment->commentable->likes_count);
    }

    public function testLazyEagerLoadingWithMorphMap(): void
    {
        Relation::morphMap(['post' => Post::class]);

        $comment = Comment::first();
        $comment->loadMorphCount('commentable', ['post' => ['likes']]);

        $this->assertEquals(2, $comment->commentable->likes_count);

        $comments = Comment::all();
        $comments->loadMorphCount('commentable', ['post' => ['likes']]);

        $this->assertEquals(2, $comments[0]->commentable->likes_count);
    }
}
