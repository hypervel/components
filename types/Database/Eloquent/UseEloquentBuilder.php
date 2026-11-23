<?php

declare(strict_types=1);

namespace Hypervel\Types\UseEloquentBuilder;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('Hypervel\Types\UseEloquentBuilder\PostBuilder<Hypervel\Types\UseEloquentBuilder\Post>', Post::query());
    assertType('Hypervel\Types\UseEloquentBuilder\PostBuilder<Hypervel\Types\UseEloquentBuilder\FeaturedPost>', FeaturedPost::query());
    assertType('Hypervel\Types\UseEloquentBuilder\FeaturedPost|null', FeaturedPost::query()->published()->first());
}

#[UseEloquentBuilder(PostBuilder::class)]
class Post extends Model
{
    /** @use HasBuilder<PostBuilder<static>> */
    use HasBuilder;
}

class FeaturedPost extends Post
{
}

/**
 * @template TModel of Post
 *
 * @extends Builder<TModel>
 */
class PostBuilder extends Builder
{
    /**
     * Limit results to published posts.
     */
    public function published(): static
    {
        return $this->whereNotNull('published_at');
    }
}
