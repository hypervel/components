<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentModelRelationAutoloadTest;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentModelRelationAutoloadTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('post_id')->nullable();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->morphs('commentable');
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->increments('id');
            $table->morphs('likeable');
        });
    }

    public function testRelationAutoloadForCollection()
    {
        $post1 = Post::create();
        $comment1 = $post1->comments()->create(['parent_id' => null]);
        $comment2 = $post1->comments()->create(['parent_id' => $comment1->id]);
        $comment2->likes()->create();
        $comment2->likes()->create();

        $post2 = Post::create();
        $comment3 = $post2->comments()->create(['parent_id' => null]);
        $comment3->likes()->create();

        $posts = Post::get();

        DB::enableQueryLog();

        $likes = [];

        $posts->withRelationshipAutoloading();

        foreach ($posts as $post) {
            foreach ($post->comments as $comment) {
                $likes = array_merge($likes, $comment->likes->all());
            }
        }

        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(3, $likes);
        $this->assertTrue($posts[0]->comments[0]->relationLoaded('likes'));

        DB::disableQueryLog();
    }

    public function testRelationAutoloadForSingleModel()
    {
        $post = Post::create();
        $comment1 = $post->comments()->create(['parent_id' => null]);
        $comment2 = $post->comments()->create(['parent_id' => $comment1->id]);
        $comment2->likes()->create();
        $comment2->likes()->create();

        DB::enableQueryLog();

        $likes = [];

        $post->withRelationshipAutoloading();

        foreach ($post->comments as $comment) {
            $likes = array_merge($likes, $comment->likes->all());
        }

        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(2, $likes);
        $this->assertTrue($post->comments[0]->relationLoaded('likes'));

        DB::disableQueryLog();
    }

    public function testRelationAutoloadWithSerialization()
    {
        Model::automaticallyEagerLoadRelationships();

        $post = Post::create();
        $comment1 = $post->comments()->create(['parent_id' => null]);
        $comment2 = $post->comments()->create(['parent_id' => $comment1->id]);
        $comment2->likes()->create();

        DB::enableQueryLog();

        $likes = [];

        $post = serialize($post);
        $post = unserialize($post);

        foreach ($post->comments as $comment) {
            $likes = array_merge($likes, $comment->likes->all());
        }

        $this->assertCount(2, DB::getQueryLog());

        Model::automaticallyEagerLoadRelationships(false);

        DB::disableQueryLog();
    }

    public function testRelationAutoloadWithCircularRelations()
    {
        $post = Post::create();
        $comment1 = $post->comments()->create(['parent_id' => null]);
        $comment2 = $post->comments()->create(['parent_id' => $comment1->id]);
        $post->likes()->create();

        DB::enableQueryLog();

        $post->withRelationshipAutoloading();
        $comment = $post->comments->first();
        $comment->setRelation('post', $post);

        $this->assertCount(1, $post->likes);

        $this->assertCount(2, DB::getQueryLog());

        DB::disableQueryLog();
    }

    public function testRelationAutoloadWithChaperoneRelations()
    {
        Model::automaticallyEagerLoadRelationships();

        $post = Post::create();
        $comment1 = $post->comments()->create(['parent_id' => null]);
        $comment2 = $post->comments()->create(['parent_id' => $comment1->id]);
        $post->likes()->create();

        DB::enableQueryLog();

        $post->load('commentsWithChaperone');

        $this->assertCount(1, $post->likes);

        $this->assertCount(2, DB::getQueryLog());

        Model::automaticallyEagerLoadRelationships(false);

        DB::disableQueryLog();
    }

    public function testRelationAutoloadVariousNestedMorphRelations()
    {
        tap(Post::create(), function ($post) {
            $post->likes()->create();
            $post->comments()->create();
            tap($post->comments()->create(), function ($comment) {
                $comment->likes()->create();
                $comment->likes()->create();
            });
        });

        tap(Post::create(), function ($post) {
            $post->likes()->create();
            tap($post->comments()->create(), function ($comment) {
                $comment->likes()->create();
            });
        });

        tap(Video::create(), function ($video) {
            tap($video->comments()->create(), function ($comment) {
                $comment->likes()->create();
            });
        });

        tap(Video::create(), function ($video) {
            tap($video->comments()->create(), function ($comment) {
                $comment->likes()->create();
            });
        });

        $likes = Like::get();

        DB::enableQueryLog();

        $videos = [];
        $videoLike = null;

        $likes->withRelationshipAutoloading();

        foreach ($likes as $like) {
            $likeable = $like->likeable;

            if (($likeable instanceof Comment) && ($likeable->commentable instanceof Video)) {
                $videos[] = $likeable->commentable;
                $videoLike = $like;
            }
        }

        $this->assertCount(4, DB::getQueryLog());
        $this->assertCount(2, $videos);
        $this->assertTrue($videoLike->relationLoaded('likeable'));
        $this->assertTrue($videoLike->likeable->relationLoaded('commentable'));

        DB::disableQueryLog();
    }

    public function testRelationAutoloadWorksOnFactoryMake()
    {
        Model::automaticallyEagerLoadRelationships();

        DB::enableQueryLog();

        $tags = Tag::factory()->times(3)->make();

        $post = Post::create();

        $post->tags()->saveMany($tags);

        $this->assertCount(7, DB::getQueryLog());

        Model::automaticallyEagerLoadRelationships(false);

        DB::disableQueryLog();
    }
}

class TagFactory extends Factory
{
    protected ?string $model = Tag::class;

    public function definition(): array
    {
        return [];
    }
}

class Tag extends Model
{
    use HasFactory;

    public bool $timestamps = false;

    protected array $guarded = [];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if ($model->post->shouldApplyStatus()) {
                $model->status = 'Todo';
            }
        });
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
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

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function commentable()
    {
        return $this->morphTo();
    }
}

class Post extends Model
{
    public bool $timestamps = false;

    public function shouldApplyStatus(): bool
    {
        return false;
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function commentsWithChaperone()
    {
        return $this->morphMany(Comment::class, 'commentable')->chaperone();
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }
}

class Video extends Model
{
    public bool $timestamps = false;

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }
}

class Like extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function likeable()
    {
        return $this->morphTo();
    }
}
