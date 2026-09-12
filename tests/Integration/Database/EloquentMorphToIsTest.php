<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphToIsTest;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Integration\Database\Fixtures\Models\MorphToTarget\Post;

class EloquentMorphToIsTest extends DatabaseTestCase
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

    public function testParentIsNotNull(): void
    {
        $child = Comment::first();
        $parent = null;

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testParentIsModel(): void
    {
        $child = Comment::first();
        $parent = Post::first();

        $this->assertTrue($child->commentable()->is($parent));
        $this->assertFalse($child->commentable()->isNot($parent));
    }

    public function testParentIsNotAnotherModel(): void
    {
        $child = Comment::first();
        $parent = new Post;
        $parent->id = 2;

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testNullParentIsNotModel(): void
    {
        $child = Comment::first();
        $child->commentable()->dissociate();
        $parent = Post::first();

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testParentIsNotModelWithAnotherTable(): void
    {
        $child = Comment::first();
        $parent = Post::first();
        $parent->setTable('foo');

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testParentIsNotModelWithAnotherConnection(): void
    {
        $child = Comment::first();
        $parent = Post::first();
        $parent->setConnection('foo');

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }
}
