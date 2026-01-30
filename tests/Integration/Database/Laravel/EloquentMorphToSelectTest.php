<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentMorphToSelectTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentMorphToSelectTest extends DatabaseTestCase
{
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
        (new Comment())->commentable()->associate($post)->save();
    }

    public function testSelect()
    {
        $comments = Comment::with('commentable:id')->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testSelectRaw()
    {
        $comments = Comment::with(['commentable' => function ($query) {
            $query->selectRaw('id');
        }])->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testSelectSub()
    {
        $comments = Comment::with(['commentable' => function ($query) {
            $query->selectSub(function ($query) {
                $query->select('id');
            }, 'id');
        }])->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testAddSelect()
    {
        $comments = Comment::with(['commentable' => function ($query) {
            $query->addSelect('id');
        }])->get();

        $this->assertEquals(['id' => 1], $comments[0]->commentable->getAttributes());
    }

    public function testLazyLoading()
    {
        $comment = Comment::first();
        $post = $comment->commentable()->select('id')->first();

        $this->assertEquals(['id' => 1], $post->getAttributes());
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
}
