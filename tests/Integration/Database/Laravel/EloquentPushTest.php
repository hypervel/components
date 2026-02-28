<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentPushTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('user_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('comment');
            $table->unsignedInteger('post_id');
        });
    }

    public function testPushMethodSavesTheRelationshipsRecursively()
    {
        $user = new UserX();
        $user->name = 'Test';
        $user->save();
        $user->posts()->create(['title' => 'Test title']);

        $post = PostX::firstOrFail();
        $post->comments()->create(['comment' => 'Test comment']);

        $user = $user->fresh();
        $user->name = 'Test 1';
        $user->posts[0]->title = 'Test title 1';
        $user->posts[0]->comments[0]->comment = 'Test comment 1';
        $user->push();

        $this->assertSame(1, UserX::count());
        $this->assertSame('Test 1', UserX::firstOrFail()->name);
        $this->assertSame(1, PostX::count());
        $this->assertSame('Test title 1', PostX::firstOrFail()->title);
        $this->assertSame(1, CommentX::count());
        $this->assertSame('Test comment 1', CommentX::firstOrFail()->comment);
    }
}

class UserX extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    protected ?string $table = 'users';

    public function posts(): HasMany
    {
        return $this->hasMany(PostX::class, 'user_id');
    }
}

class PostX extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    protected ?string $table = 'posts';

    public function comments(): HasMany
    {
        return $this->hasMany(CommentX::class, 'post_id');
    }
}

class CommentX extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    protected ?string $table = 'comments';
}
