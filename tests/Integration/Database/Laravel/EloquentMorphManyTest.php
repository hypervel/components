<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentMorphManyTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentMorphManyTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('commentable_id');
            $table->string('commentable_type');
            $table->timestamps();
        });
    }

    public function testUpdateModelWithDefaultWithCount()
    {
        $post = Post::create(['title' => Str::random()]);

        $post->update(['title' => 'new name']);

        $this->assertSame('new name', $post->title);
    }

    public function testSelfReferencingExistenceQuery()
    {
        $post = Post::create(['title' => 'foo']);

        $comment = tap((new Comment(['name' => 'foo']))->commentable()->associate($post))->save();

        (new Comment(['name' => 'bar']))->commentable()->associate($comment)->save();

        $comments = Comment::has('replies')->get();

        $this->assertEquals([1], $comments->pluck('id')->all());
    }

    public function testCanMorphOne()
    {
        $post = Post::create(['title' => 'Your favorite book by C.S. Lewis']);

        Carbon::setTestNow('1990-02-02 12:00:00');
        $oldestComment = tap((new Comment(['name' => 'The Allegory Of Love']))->commentable()->associate($post))->save();

        Carbon::setTestNow('2000-07-02 09:00:00');
        tap((new Comment(['name' => 'The Screwtape Letters']))->commentable()->associate($post))->save();

        Carbon::setTestNow('2022-01-01 00:00:00');
        $latestComment = tap((new Comment(['name' => 'The Silver Chair']))->commentable()->associate($post))->save();

        $this->assertInstanceOf(MorphOne::class, $post->comments()->one());

        $this->assertEquals($latestComment->id, $post->latestComment->id);
        $this->assertEquals($oldestComment->id, $post->oldestComment->id);
    }
}

class Post extends Model
{
    public ?string $table = 'posts';

    public bool $timestamps = true;

    protected array $guarded = [];

    protected array $withCount = ['comments'];

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function latestComment(): MorphOne
    {
        return $this->comments()->one()->latestOfMany();
    }

    public function oldestComment(): MorphOne
    {
        return $this->comments()->one()->oldestOfMany();
    }
}

class Comment extends Model
{
    public ?string $table = 'comments';

    public bool $timestamps = true;

    protected array $guarded = [];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function replies(): MorphMany
    {
        return $this->morphMany(self::class, 'commentable');
    }
}
