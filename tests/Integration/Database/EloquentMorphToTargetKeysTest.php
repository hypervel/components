<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentMorphToTargetKeysTest;

use Closure;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EloquentMorphToTargetKeysTest extends DatabaseTestCase
{
    /**
     * Create the test tables.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->increments('id');
        });

        // A non-null id makes a lookup through the wrong key miss the video's comment. With a null id, SQL's
        // NULL comparison would also drop that comment from whereNotMorphedTo() and hide the mistake.
        Schema::create('videos', function (Blueprint $table): void {
            $table->string('uuid')->primary();
            $table->string('id');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('commentable_type');
            $table->string('commentable_id');
        });

        Post::create();
        Video::create(['uuid' => 'video-key', 'id' => 'other-key']);

        Comment::create(['commentable_id' => '1']);
        Comment::create(['commentable_type' => Video::class, 'commentable_id' => 'video-key']);
    }

    /**
     * @param Closure(): (MorphTo|string) $relation
     */
    #[DataProvider('morphPredicates')]
    public function testMorphPredicatesUseTheTargetsPrimaryKey(Closure $relation, string $method, int $expectedCommentId): void
    {
        $comments = Comment::query()->{$method}($relation(), Video::find('video-key'))->pluck('id')->all();

        $this->assertSame([$expectedCommentId], $comments);
    }

    /**
     * Provide both predicates with the relation given by name and as an object.
     */
    public static function morphPredicates(): iterable
    {
        $name = fn (): string => 'commentable';
        $object = fn (): MorphTo => (new Comment)->commentable();

        yield 'whereMorphedTo by name' => [$name, 'whereMorphedTo', 2];
        yield 'whereMorphedTo by object' => [$object, 'whereMorphedTo', 2];
        yield 'whereNotMorphedTo by name' => [$name, 'whereNotMorphedTo', 1];
        yield 'whereNotMorphedTo by object' => [$object, 'whereNotMorphedTo', 1];
    }

    public function testWhereHasMorphUsesTheTargetsPrimaryKey(): void
    {
        $this->assertSame([2], Comment::whereHasMorph('commentable', Video::class)->pluck('id')->all());
    }

    public function testEagerLoadingMatchesTargetsWithAnotherPrimaryKey(): void
    {
        $comments = Comment::with('commentable')->orderBy('id')->get();

        $this->assertInstanceOf(Post::class, $comments[0]->commentable);
        $this->assertSame('video-key', $comments[1]->commentable->getKey());
    }

    public function testAssociatingALoadedRelationUsesTheTargetsPrimaryKey(): void
    {
        $comment = Comment::findOrFail(1);

        $comment->commentable()->associate(Video::find('video-key'))->save();

        $this->assertSame('video-key', $comment->fresh()->commentable_id);
    }

    public function testFactoryRelationshipsUseTheTargetsPrimaryKey(): void
    {
        $comment = CommentFactory::new()->for(Video::find('video-key'), 'commentable')->create();

        $this->assertSame('video-key', $comment->commentable_id);
        $this->assertSame(Video::class, $comment->commentable_type);
    }
}

class Post extends Model
{
    public bool $timestamps = false;
}

class Video extends Model
{
    public bool $timestamps = false;

    protected ?string $primaryKey = 'uuid';

    protected string $keyType = 'string';

    public bool $incrementing = false;

    protected array $fillable = ['uuid', 'id'];
}

class Comment extends Model
{
    public bool $timestamps = false;

    protected array $attributes = ['commentable_type' => Post::class];

    protected array $fillable = ['commentable_type', 'commentable_id'];

    /**
     * Get the commented model.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected ?string $model = Comment::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}
