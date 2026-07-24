<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphToEagerLoadTest;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentMorphToEagerLoadTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->string('slug')->primary();
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->string('id')->primary();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('commentable_type');
            $table->string('commentable_id');
        });

        $post = Post::create();
        $article = Article::create(['slug' => ArticleSlug::Review->value]);
        $video = Video::create(['id' => '550e8400-e29b-41d4-a716-446655440000']);

        (new Comment)->commentable()->associate($post)->save();
        (new Comment)->commentable()->associate($article)->save();

        $comment = new Comment;
        $comment->commentable_type = Video::class;
        $comment->commentable_id = (string) $video->id;
        $comment->save();
    }

    public function testEagerLoadingResolvesRelationWithPrimitivePrimaryKey(): void
    {
        $comments = Comment::with('commentable')
            ->where('commentable_type', Post::class)
            ->get();

        $this->assertNotNull($comments[0]->commentable);
        $this->assertInstanceOf(Post::class, $comments[0]->commentable);
    }

    public function testEagerLoadingResolvesRelationWithBackedEnumPrimaryKey(): void
    {
        $comments = Comment::with('commentable')
            ->where('commentable_type', Article::class)
            ->get();

        $this->assertNotNull($comments[0]->commentable);
        $this->assertInstanceOf(Article::class, $comments[0]->commentable);
        $this->assertSame(ArticleSlug::Review, $comments[0]->commentable->slug);
    }

    public function testEagerLoadingResolvesRelationWithUuidValueObjectPrimaryKey(): void
    {
        $comments = Comment::with('commentable')
            ->where('commentable_type', Video::class)
            ->get();

        $this->assertNotNull($comments[0]->commentable);
        $this->assertInstanceOf(Video::class, $comments[0]->commentable);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', (string) $comments[0]->commentable->id);
    }
}

enum ArticleSlug: string
{
    case Review = 'review';
}

class Post extends Model
{
    public bool $timestamps = false;
}

class Article extends Model
{
    public bool $timestamps = false;

    protected string $primaryKey = 'slug';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $casts = ['slug' => ArticleSlug::class];

    protected array $fillable = ['slug'];
}

class Comment extends Model
{
    public bool $timestamps = false;

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class Uuid
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

class UuidCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return new Uuid($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return (string) $value;
    }
}

class Video extends Model
{
    public bool $timestamps = false;

    public bool $incrementing = false;

    protected array $fillable = ['id'];

    protected string $keyType = 'string';

    protected array $casts = ['id' => UuidCast::class];
}
