<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphConstrainTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Database\Fixtures\Models\Comment;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentMorphConstrainTest extends DatabaseTestCase
{
    /**
     * Create the test tables and related models.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('post_visible');
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('video_visible');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('commentable_type');
            $table->integer('commentable_id');
        });

        $post1 = Post::create(['post_visible' => true]);
        (new Comment)->commentable()->associate($post1)->save();

        $post2 = Post::create(['post_visible' => false]);
        (new Comment)->commentable()->associate($post2)->save();

        $video1 = Video::create(['video_visible' => true]);
        (new Comment)->commentable()->associate($video1)->save();

        $video2 = Video::create(['video_visible' => false]);
        (new Comment)->commentable()->associate($video2)->save();
    }

    public function testMorphConstraints(): void
    {
        $comments = Comment::query()
            ->with(['commentable' => function (MorphTo $morphTo): void {
                $morphTo->constrain([
                    Post::class => function ($query) {
                        $query->where('post_visible', true);
                    },
                    Video::class => function ($query) {
                        $query->where('video_visible', true);
                    },
                ]);
            }])
            ->get();

        $this->assertTrue($comments[0]->commentable->post_visible);
        $this->assertNull($comments[1]->commentable);
        $this->assertTrue($comments[2]->commentable->video_visible);
        $this->assertNull($comments[3]->commentable);
    }

    public function testMorphConstraintsWithMorphMap(): void
    {
        Relation::morphMap(['post' => Post::class, 'video' => Video::class]);

        Comment::where('commentable_type', Post::class)->update(['commentable_type' => 'post']);
        Comment::where('commentable_type', Video::class)->update(['commentable_type' => 'video']);

        $comments = Comment::query()
            ->with(['commentable' => function (MorphTo $morphTo): void {
                $morphTo->constrain([
                    'post' => fn ($query) => $query->where('post_visible', true),
                    'video' => fn ($query) => $query->where('video_visible', true),
                ]);
            }])
            ->orderBy('id')
            ->get();

        $this->assertTrue($comments[0]->commentable->post_visible);
        $this->assertNull($comments[1]->commentable);
        $this->assertTrue($comments[2]->commentable->video_visible);
        $this->assertNull($comments[3]->commentable);
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    protected array $fillable = ['post_visible'];

    protected array $casts = ['post_visible' => 'boolean'];
}

class Video extends Model
{
    public bool $timestamps = false;

    protected array $fillable = ['video_visible'];

    protected array $casts = ['video_visible' => 'boolean'];
}
