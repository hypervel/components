<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentHasOneIsTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Attachment;

class EloquentHasOneIsTest extends DatabaseTestCase
{
    /**
     * Create the test tables and related models.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('post_id')->nullable();
        });

        $post = Post::create();
        $post->attachment()->create();
    }

    public function testChildIsNotNull(): void
    {
        $parent = Post::first();
        $child = null;

        $this->assertFalse($parent->attachment()->is($child));
        $this->assertTrue($parent->attachment()->isNot($child));
    }

    public function testChildIsModel(): void
    {
        $parent = Post::first();
        $child = Attachment::first();

        $this->assertTrue($parent->attachment()->is($child));
        $this->assertFalse($parent->attachment()->isNot($child));
    }

    public function testChildIsNotAnotherModel(): void
    {
        $parent = Post::first();
        $child = new Attachment;
        $child->id = 2;

        $this->assertFalse($parent->attachment()->is($child));
        $this->assertTrue($parent->attachment()->isNot($child));
    }

    public function testNullChildIsNotModel(): void
    {
        $parent = Post::first();
        $child = Attachment::first();
        $child->post_id = null;

        $this->assertFalse($parent->attachment()->is($child));
        $this->assertTrue($parent->attachment()->isNot($child));
    }

    public function testChildIsNotModelWithAnotherTable(): void
    {
        $parent = Post::first();
        $child = Attachment::first();
        $child->setTable('foo');

        $this->assertFalse($parent->attachment()->is($child));
        $this->assertTrue($parent->attachment()->isNot($child));
    }

    public function testChildIsNotModelWithAnotherConnection(): void
    {
        $parent = Post::first();
        $child = Attachment::first();
        $child->setConnection('foo');

        $this->assertFalse($parent->attachment()->is($child));
        $this->assertTrue($parent->attachment()->isNot($child));
    }
}

class Post extends Model
{
    /**
     * Get the post's attachment.
     */
    public function attachment(): HasOne
    {
        return $this->hasOne(Attachment::class);
    }
}
