<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentWhereHasMorphTest;

use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class EloquentWhereHasMorphTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->softDeletes();
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->nullableMorphs('commentable');
            $table->softDeletes();
        });

        $models = [];

        $models[] = Post::create(['title' => 'foo']);
        $models[] = Post::create(['title' => 'bar']);
        $models[] = Post::create(['title' => 'baz']);
        end($models)->delete();

        $models[] = Video::create(['title' => 'foo']);
        $models[] = Video::create(['title' => 'bar']);
        $models[] = Video::create(['title' => 'baz']);
        $models[] = null; // deleted
        $models[] = null; // deleted

        foreach ($models as $model) {
            $comment = new Comment;
            $comment->commentable()->associate($model);
            $comment->title = 'foo';
            $comment->save();
        }
    }

    public function testWhereHasMorph()
    {
        $comments = Comment::whereHasMorph('commentable', [Post::class, Video::class], function (Builder $query) {
            $query->where('title', 'foo');
        })->orderBy('id')->get();

        $this->assertEquals([1, 4], $comments->pluck('id')->all());
    }

    public function testWhereHasMorphWithMorphMap()
    {
        Relation::morphMap(['posts' => Post::class]);

        Comment::where('commentable_type', Post::class)->update(['commentable_type' => 'posts']);

        try {
            $comments = Comment::whereHasMorph('commentable', [Post::class, Video::class], function (Builder $query) {
                $query->where('title', 'foo');
            })->orderBy('id')->get();

            $this->assertEquals([1, 4], $comments->pluck('id')->all());
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function testWhereHasMorphWithWildcard()
    {
        // Test newModelQuery() without global scopes.
        Comment::where('commentable_type', Video::class)->delete();

        $comments = Comment::withTrashed()
            ->whereHasMorph('commentable', '*', function (Builder $query) {
                $query->where('title', 'foo');
            })->orderBy('id')->get();

        $this->assertEquals([1, 4], $comments->pluck('id')->all());
    }

    #[DataProvider('wildcardCountComparisonProvider')]
    public function testWhereHasMorphWithWildcardCountComparisons(string $operator, array $zeroIds, array $columnIds): void
    {
        $this->assertSame($zeroIds, Comment::whereHasMorph('commentable', '*', null, $operator, 0)
            ->orderBy('id')->pluck('id')->all());

        $this->assertSame($zeroIds, Comment::whereHasMorph('commentable', '*', null, $operator, new Expression('0'))
            ->orderBy('id')->pluck('id')->all());

        $count = m::mock(ExpressionContract::class);
        // Decimal subtraction avoids unsigned integer underflow on MySQL and MariaDB.
        $count->shouldReceive('getValue')->andReturn('comments.id - 7.0');

        $query = Comment::whereHasMorph('commentable', '*', null, $operator, $count)->orderBy('id');

        $this->assertSame($columnIds, $query->pluck('id')->all());
        $this->assertEqualsCanonicalizing([Post::class, Video::class], $query->getBindings());
    }

    /**
     * Provide zero-count and row-dependent comparisons, including nullable morphs.
     */
    public static function wildcardCountComparisonProvider(): array
    {
        return [
            'equal' => ['=', [3, 7, 8], [7]],
            'null-safe equal' => ['<=>', [3, 7, 8], [7]],
            'not equal' => ['!=', [1, 2, 4, 5, 6], [1, 2, 3, 4, 5, 6, 8]],
            'alternate not equal' => ['<>', [1, 2, 4, 5, 6], [1, 2, 3, 4, 5, 6, 8]],
            'less than' => ['<', [], [8]],
            'less than or equal' => ['<=', [3, 7, 8], [7, 8]],
            'greater than' => ['>', [1, 2, 4, 5, 6], [1, 2, 3, 4, 5, 6]],
            'greater than or equal' => ['>=', [1, 2, 3, 4, 5, 6, 7, 8], [1, 2, 3, 4, 5, 6, 7]],
        ];
    }

    public function testWhereHasMorphWithExpressionCountAndOnlyNullMorphTypes(): void
    {
        Comment::whereNotNull('commentable_type')->forceDelete();

        $this->assertSame([7], Comment::whereHasMorph('commentable', '*', null, '=', new Expression('comments.id - 7.0'))
            ->orderBy('id')->pluck('id')->all());
    }

    public function testWhereHasMorphWithExpressionCountAndExplicitTypes(): void
    {
        $this->assertSame([3], Comment::whereHasMorph('commentable', [Post::class, Video::class], null, '=', new Expression('0'))
            ->orderBy('id')->pluck('id')->all());
    }

    public function testWhereHasMorphWithExpressionCountIsLogicallyGrouped(): void
    {
        $this->assertSame([], Comment::whereNot('title', 'foo')
            ->whereHasMorph('commentable', '*', null, '=', new Expression('0'))->pluck('id')->all());
        $this->assertSame([], Comment::whereHasMorph('commentable', '*', null, '=', new Expression('0'))
            ->whereNot('title', 'foo')->pluck('id')->all());
    }

    public function testWhereHasMorphWithWildcardAndMorphMap()
    {
        Relation::morphMap(['posts' => Post::class]);

        Comment::where('commentable_type', Post::class)->update(['commentable_type' => 'posts']);

        try {
            $comments = Comment::whereHasMorph('commentable', '*', function (Builder $query) {
                $query->where('title', 'foo');
            })->orderBy('id')->get();

            $this->assertEquals([1, 4], $comments->pluck('id')->all());
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function testWhereHasMorphWithWildcardAndOnlyNullMorphTypes()
    {
        Comment::whereNotNull('commentable_type')->forceDelete();

        $comments = Comment::query()
            ->whereHasMorph('commentable', '*', function (Builder $query) {
                $query->where('title', 'foo');
            })
            ->orderBy('id')->get();

        $this->assertEmpty($comments->pluck('id')->all());
    }

    public function testWhereHasMorphWithRelationConstraint()
    {
        $comments = Comment::whereHasMorph('commentableWithConstraint', Video::class, function (Builder $query) {
            $query->where('title', 'like', 'ba%');
        })->orderBy('id')->get();

        $this->assertEquals([5], $comments->pluck('id')->all());
    }

    public function testWhereHasMorphWitDifferentConstraints()
    {
        $comments = Comment::whereHasMorph('commentable', [Post::class, Video::class], function (Builder $query, $type) {
            if ($type === Post::class) {
                $query->where('title', 'foo');
            }

            if ($type === Video::class) {
                $query->where('title', 'bar');
            }
        })->orderBy('id')->get();

        $this->assertEquals([1, 5], $comments->pluck('id')->all());
    }

    public function testWhereHasMorphWithOwnerKey()
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('slug')->nullable();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_commentable_type_commentable_id_index');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->string('commentable_id')->nullable()->change();
        });

        Post::where('id', 1)->update(['slug' => 'foo']);

        Comment::where('id', 1)->update(['commentable_id' => 'foo']);

        $comments = Comment::whereHasMorph('commentableWithOwnerKey', Post::class, function (Builder $query) {
            $query->where('title', 'foo');
        })->orderBy('id')->get();

        $this->assertEquals([1], $comments->pluck('id')->all());
    }

    public function testHasMorph()
    {
        $comments = Comment::hasMorph('commentable', Post::class)->orderBy('id')->get();

        $this->assertEquals([1, 2], $comments->pluck('id')->all());
    }

    public function testOrHasMorph()
    {
        $comments = Comment::where('id', 1)->orHasMorph('commentable', Video::class)->orderBy('id')->get();

        $this->assertEquals([1, 4, 5, 6], $comments->pluck('id')->all());
    }

    public function testDoesntHaveMorph()
    {
        $comments = Comment::doesntHaveMorph('commentable', Post::class)->orderBy('id')->get();

        $this->assertEquals([3], $comments->pluck('id')->all());
    }

    public function testOrDoesntHaveMorph()
    {
        $comments = Comment::where('id', 1)->orDoesntHaveMorph('commentable', Post::class)->orderBy('id')->get();

        $this->assertEquals([1, 3], $comments->pluck('id')->all());
    }

    public function testOrWhereHasMorph()
    {
        $comments = Comment::where('id', 1)
            ->orWhereHasMorph('commentable', Video::class, function (Builder $query) {
                $query->where('title', 'foo');
            })->orderBy('id')->get();

        $this->assertEquals([1, 4], $comments->pluck('id')->all());
    }

    public function testOrWhereHasMorphWithWildcardAndOnlyNullMorphTypes()
    {
        Comment::whereNotNull('commentable_type')->forceDelete();

        $comments = Comment::where('id', 7)
            ->orWhereHasMorph('commentable', '*', function (Builder $query) {
                $query->where('title', 'foo');
            })->orderBy('id')->get();

        $this->assertEquals([7], $comments->pluck('id')->all());
    }

    public function testWhereDoesntHaveMorph()
    {
        $comments = Comment::whereDoesntHaveMorph('commentable', Post::class, function (Builder $query) {
            $query->where('title', 'foo');
        })->orderBy('id')->get();

        $this->assertEquals([2, 3], $comments->pluck('id')->all());
    }

    public function testWhereDoesntHaveMorphWithWildcardAndOnlyNullMorphTypes()
    {
        Comment::whereNotNull('commentable_type')->forceDelete();

        $comments = Comment::whereDoesntHaveMorph('commentable', [], function (Builder $query) {
            $query->where('title', 'foo');
        })->orderBy('id')->get();

        $this->assertEquals([7, 8], $comments->pluck('id')->all());
    }

    public function testOrWhereDoesntHaveMorph()
    {
        $comments = Comment::where('id', 1)
            ->orWhereDoesntHaveMorph('commentable', Post::class, function (Builder $query) {
                $query->where('title', 'foo');
            })->orderBy('id')->get();

        $this->assertEquals([1, 2, 3], $comments->pluck('id')->all());
    }

    public function testModelScopesAreAccessible()
    {
        $comments = Comment::whereHasMorph('commentable', [Post::class, Video::class], function (Builder $query) {
            $query->someSharedModelScope();
        })->orderBy('id')->get();

        $this->assertEquals([1, 4], $comments->pluck('id')->all());
    }

    public function testWhereDoesntHaveMorphWithNullableMorph()
    {
        $comments = Comment::whereDoesntHaveMorph('commentable', '*')->orderBy('id')->get();

        $this->assertEquals([3, 7, 8], $comments->pluck('id')->all());
    }

    public function testWhereDoesntHaveMorphWithNullableMorphAndAdditionalWhereIsLogicallyGrouped()
    {
        $commentsWhereFirst = Comment::whereNot('title', 'foo')
            ->whereDoesntHaveMorph('commentable', '*')
            ->orderBy('id')
            ->get();

        $commentsWhereLast = Comment::whereDoesntHaveMorph('commentable', '*')
            ->whereNot('title', 'foo')
            ->orderBy('id')
            ->get();

        $this->assertCount(0, $commentsWhereFirst);
        $this->assertCount(0, $commentsWhereLast);
    }
}

class Comment extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];

    public function commentable()
    {
        return $this->morphTo();
    }

    public function commentableWithConstraint()
    {
        return $this->morphTo('commentable')->where('title', 'bar');
    }

    public function commentableWithOwnerKey()
    {
        return $this->morphTo('commentable', null, null, 'slug');
    }
}

class Post extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];

    public function scopeSomeSharedModelScope($query)
    {
        $query->where('title', '=', 'foo');
    }
}

class Video extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function scopeSomeSharedModelScope($query)
    {
        $query->where('title', '=', 'foo');
    }
}
