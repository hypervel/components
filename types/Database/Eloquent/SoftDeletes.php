<?php

declare(strict_types=1);

namespace Hypervel\Types\SoftDeletes;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\SoftDeletes;

use function PHPStan\Testing\assertType;

function test(Post $post, Article $article): void
{
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\SoftDeletes\Post>', Post::query()->withTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\SoftDeletes\Post>', Post::query()->withoutTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\SoftDeletes\Post>', Post::query()->onlyTrashed());
    assertType('int', Post::query()->restore());
    assertType('Hypervel\Types\SoftDeletes\Post', Post::query()->restoreOrCreate(['title' => 'Draft']));
    assertType('Hypervel\Types\SoftDeletes\Post', Post::query()->createOrRestore(['title' => 'Draft']));

    assertType('Hypervel\Types\SoftDeletes\ArticleBuilder<Hypervel\Types\SoftDeletes\Article>', Article::withTrashed());
    assertType('Hypervel\Types\SoftDeletes\ArticleBuilder<Hypervel\Types\SoftDeletes\Article>', Article::query()->onlyTrashed()->published());
    assertType('Hypervel\Types\SoftDeletes\ArticleBuilder<Hypervel\Types\SoftDeletes\Article>', $article->withoutTrashed());
    assertType('Hypervel\Types\SoftDeletes\ArticleBuilder<Hypervel\Types\SoftDeletes\FeaturedArticle>', FeaturedArticle::withTrashed());
    assertType('Hypervel\Types\SoftDeletes\FeaturedArticle', FeaturedArticle::query()->restoreOrCreate());

    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\SoftDeletes\Comment, Hypervel\Types\SoftDeletes\Post>', $post->comments()->withTrashed());
    assertType('int', $post->comments()->restore());
    assertType('Hypervel\Types\SoftDeletes\Comment', $post->comments()->createOrRestore());

    // The macro runs before a same-named scope, as in Builder::__call().
    assertType('Hypervel\Types\SoftDeletes\ArticleBuilder<Hypervel\Types\SoftDeletes\Archive>', Archive::onlyTrashed());
    assertType('Hypervel\Types\SoftDeletes\ArticleBuilder<Hypervel\Types\SoftDeletes\Archive>', Archive::query()->onlyTrashed());

    // Macro names are case-sensitive, and only soft-deleting models have them.
    Post::query()->WithTrashed(); // @phpstan-ignore method.notFound
    Plain::query()->withTrashed(); // @phpstan-ignore method.notFound
}

class Post extends Model
{
    use SoftDeletes;

    /**
     * Get the post's comments.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}

class Comment extends Model
{
    use SoftDeletes;
}

trait Archivable
{
    use SoftDeletes;
}

class Article extends Model
{
    use Archivable;

    /** @use HasBuilder<ArticleBuilder<static>> */
    use HasBuilder;

    protected static string $builder = ArticleBuilder::class;
}

class FeaturedArticle extends Article
{
}

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ArticleBuilder extends Builder
{
    /**
     * Limit the query to published articles.
     */
    public function published(): static
    {
        return $this->whereNotNull('published_at');
    }
}

class Archive extends Model
{
    use SoftDeletes;

    /** @use HasBuilder<ArticleBuilder<static>> */
    use HasBuilder;

    protected static string $builder = ArticleBuilder::class;

    /**
     * Limit the query to archives trashed for the given reason.
     *
     * @param Builder<self> $query
     */
    protected function scopeOnlyTrashed(Builder $query, string $reason): void
    {
    }
}

class Plain extends Model
{
}
