<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentMorphLazyEagerLoadingTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentMorphLazyEagerLoadingTest extends DatabaseTestCase
{
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

        $post = tap((new Post())->user()->associate($user))->save();

        (new Comment())->commentable()->associate($post)->save();
    }

    public function testLazyEagerLoading()
    {
        $comment = Comment::first();

        $comment->loadMorph('commentable', [
            Post::class => ['user'],
        ]);

        $this->assertTrue($comment->relationLoaded('commentable'));
        $this->assertTrue($comment->commentable->relationLoaded('user'));
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

    protected string $primaryKey = 'post_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

class User extends Model
{
    public bool $timestamps = false;
}
