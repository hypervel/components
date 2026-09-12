<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphToSelectTest;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Integration\Database\Fixtures\Models\MorphToTarget\Post;

class EloquentMorphToSelectTest extends DatabaseTestCase
{
    /**
     * Create the test tables and related models.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $post = Post::create();
        (new Comment)->commentable()->associate($post)->save();
    }

    public function testSelect(): void
    {
        $comments = Comment::with('commentable:id')->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testSelectRaw(): void
    {
        $comments = Comment::with(['commentable' => function ($query) {
            $query->selectRaw('id');
        }])->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testSelectSub(): void
    {
        $comments = Comment::with(['commentable' => function ($query) {
            $query->selectSub(function ($query) {
                $query->select('id');
            }, 'id');
        }])->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testAddSelect(): void
    {
        $comments = Comment::with(['commentable' => function ($query) {
            $query->addSelect('id');
        }])->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testLazyLoading(): void
    {
        $comment = Comment::first();
        $post = $comment->commentable()->select('id')->first();

        $this->assertEquals(['id' => 1], $post->getAttributes());
    }
}
