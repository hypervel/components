<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentMorphToIsTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentMorphToIsTest extends DatabaseTestCase
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

    public function testParentIsNotNull()
    {
        $child = Comment::first();
        $parent = null;

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testParentIsModel()
    {
        $child = Comment::first();
        $parent = Post::first();

        $this->assertTrue($child->commentable()->is($parent));
        $this->assertFalse($child->commentable()->isNot($parent));
    }

    public function testParentIsNotAnotherModel()
    {
        $child = Comment::first();
        $parent = new Post();
        $parent->id = 2;

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testNullParentIsNotModel()
    {
        $child = Comment::first();
        $child->commentable()->dissociate();
        $parent = Post::first();

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testParentIsNotModelWithAnotherTable()
    {
        $child = Comment::first();
        $parent = Post::first();
        $parent->setTable('foo');

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
    }

    public function testParentIsNotModelWithAnotherConnection()
    {
        $child = Comment::first();
        $parent = Post::first();
        $parent->setConnection('foo');

        $this->assertFalse($child->commentable()->is($parent));
        $this->assertTrue($child->commentable()->isNot($parent));
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
