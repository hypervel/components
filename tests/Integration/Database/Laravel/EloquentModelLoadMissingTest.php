<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentModelLoadMissingTest;

use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentModelLoadMissingTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('comment_mentions_users', function (Blueprint $table) {
            $table->unsignedInteger('comment_id');
            $table->unsignedInteger('user_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('first_comment_id')->nullable();
            $table->string('content')->nullable();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedInteger('post_id');
            $table->string('content')->nullable();
        });

        Post::create();

        Comment::create(['parent_id' => null, 'post_id' => 1, 'content' => 'Hello <u:1> <u:2>']);
        Comment::create(['parent_id' => 1, 'post_id' => 1]);

        User::create(['name' => 'Taylor']);
        User::create(['name' => 'Otwell']);

        Comment::first()->mentionsUsers()->attach([1, 2]);

        Post::first()->update(['first_comment_id' => 1]);
    }

    public function testLoadMissing()
    {
        $post = Post::with('comments')->first();

        DB::enableQueryLog();

        $post->loadMissing('comments.parent');

        $this->assertCount(1, DB::getQueryLog());
        $this->assertTrue($post->comments[0]->relationLoaded('parent'));
    }

    public function testLoadMissingNoUnnecessaryAttributeMutatorAccess()
    {
        $posts = Post::all();

        DB::enableQueryLog();

        $posts->loadMissing('firstComment.parent');

        $this->assertCount(1, DB::getQueryLog());
    }
}

class Comment extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function parent()
    {
        return $this->belongsTo(self::class);
    }

    public function mentionsUsers()
    {
        return $this->belongsToMany(User::class, 'comment_mentions_users');
    }

    public function content(): Attribute
    {
        return new Attribute(function (?string $value) {
            return preg_replace_callback('/<u:(\d+)>/', function ($matches) {
                return '@' . $this->mentionsUsers->find($matches[1])?->name;
            }, $value);
        });
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function firstComment()
    {
        return $this->belongsTo(Comment::class, 'first_comment_id');
    }
}

class User extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}
