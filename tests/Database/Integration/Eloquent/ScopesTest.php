<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration\Eloquent;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Tests\Database\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class ScopesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ScopeArticle::create(['title' => 'Published Article 1', 'status' => 'published', 'category' => 'tech', 'views' => 100, 'is_featured' => true]);
        ScopeArticle::create(['title' => 'Published Article 2', 'status' => 'published', 'category' => 'tech', 'views' => 50]);
        ScopeArticle::create(['title' => 'Draft Article', 'status' => 'draft', 'category' => 'news', 'views' => 0]);
        ScopeArticle::create(['title' => 'Archived Article', 'status' => 'archived', 'category' => 'tech', 'views' => 200]);
        ScopeArticle::create(['title' => 'Popular Article', 'status' => 'published', 'category' => 'news', 'views' => 500, 'is_featured' => true]);
    }

    public function testLocalScope(): void
    {
        $published = ScopeArticle::published()->get();

        $this->assertCount(3, $published);
        foreach ($published as $article) {
            $this->assertSame('published', $article->status);
        }
    }

    public function testLocalScopeWithParameter(): void
    {
        $techArticles = ScopeArticle::inCategory('tech')->get();

        $this->assertCount(3, $techArticles);
        foreach ($techArticles as $article) {
            $this->assertSame('tech', $article->category);
        }
    }

    public function testMultipleScopesCombined(): void
    {
        $publishedTech = ScopeArticle::published()->inCategory('tech')->get();

        $this->assertCount(2, $publishedTech);
    }

    public function testScopeWithMinViews(): void
    {
        $popular = ScopeArticle::minViews(100)->get();

        $this->assertCount(3, $popular);
        foreach ($popular as $article) {
            $this->assertGreaterThanOrEqual(100, $article->views);
        }
    }

    public function testFeaturedScope(): void
    {
        $featured = ScopeArticle::featured()->get();

        $this->assertCount(2, $featured);
        foreach ($featured as $article) {
            $this->assertTrue($article->is_featured);
        }
    }

    public function testChainingMultipleScopes(): void
    {
        $result = ScopeArticle::published()
            ->featured()
            ->minViews(50)
            ->get();

        $this->assertCount(2, $result);
    }

    public function testScopeWithOrderBy(): void
    {
        $articles = ScopeArticle::popular()->get();

        $this->assertSame('Popular Article', $articles->first()->title);
        $this->assertSame('Draft Article', $articles->last()->title);
    }

    public function testGlobalScope(): void
    {
        ScopeArticle::query()->delete();

        GlobalScopeArticle::create(['title' => 'Global Published', 'status' => 'published']);
        GlobalScopeArticle::create(['title' => 'Global Draft', 'status' => 'draft']);

        $all = GlobalScopeArticle::all();

        $this->assertCount(1, $all);
        $this->assertSame('Global Published', $all->first()->title);
    }

    public function testWithoutGlobalScope(): void
    {
        ScopeArticle::query()->delete();

        GlobalScopeArticle::create(['title' => 'Without Scope Published', 'status' => 'published']);
        GlobalScopeArticle::create(['title' => 'Without Scope Draft', 'status' => 'draft']);

        $all = GlobalScopeArticle::withoutGlobalScope(PublishedScope::class)->get();

        $this->assertCount(2, $all);
    }

    public function testWithoutGlobalScopes(): void
    {
        ScopeArticle::query()->delete();

        GlobalScopeArticle::create(['title' => 'Test Published', 'status' => 'published']);
        GlobalScopeArticle::create(['title' => 'Test Draft', 'status' => 'draft']);

        $all = GlobalScopeArticle::withoutGlobalScopes()->get();

        $this->assertCount(2, $all);
    }

    public function testDynamicScope(): void
    {
        $articles = ScopeArticle::status('archived')->get();

        $this->assertCount(1, $articles);
        $this->assertSame('Archived Article', $articles->first()->title);
    }

    public function testScopeOnRelation(): void
    {
        $author = ScopeAuthor::create(['name' => 'John']);

        ScopeArticle::where('title', 'Published Article 1')->update(['author_id' => $author->id]);
        ScopeArticle::where('title', 'Draft Article')->update(['author_id' => $author->id]);
        ScopeArticle::where('title', 'Archived Article')->update(['author_id' => $author->id]);

        $publishedByAuthor = $author->articles()->published()->get();

        $this->assertCount(1, $publishedByAuthor);
        $this->assertSame('Published Article 1', $publishedByAuthor->first()->title);
    }

    public function testScopeWithCount(): void
    {
        $count = ScopeArticle::published()->count();

        $this->assertSame(3, $count);
    }

    public function testScopeWithFirst(): void
    {
        $article = ScopeArticle::published()->inCategory('news')->first();

        $this->assertNotNull($article);
        $this->assertSame('Popular Article', $article->title);
    }

    public function testScopeWithExists(): void
    {
        $this->assertTrue(ScopeArticle::published()->exists());
        $this->assertFalse(ScopeArticle::status('nonexistent')->exists());
    }

    public function testScopeReturnsBuilder(): void
    {
        $builder = ScopeArticle::published();

        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testScopeWithPluck(): void
    {
        $titles = ScopeArticle::published()->pluck('title');

        $this->assertCount(3, $titles);
        $this->assertContains('Published Article 1', $titles->toArray());
    }

    public function testScopeWithAggregate(): void
    {
        $totalViews = ScopeArticle::published()->sum('views');

        $this->assertEquals(650, $totalViews);
    }

    public function testOrScope(): void
    {
        $articles = ScopeArticle::where(function ($query) {
            $query->featured()->orWhere('views', '>', 100);
        })->get();

        $this->assertCount(3, $articles);
    }
}

class ScopeArticle extends Model
{
    protected ?string $table = 'scope_articles';

    protected array $fillable = ['title', 'status', 'category', 'views', 'is_featured', 'author_id'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeMinViews(Builder $query, int $views): Builder
    {
        return $query->where('views', '>=', $views);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->orderBy('views', 'desc');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function author()
    {
        return $this->belongsTo(ScopeAuthor::class, 'author_id');
    }
}

class ScopeAuthor extends Model
{
    protected ?string $table = 'scope_authors';

    protected array $fillable = ['name'];

    public function articles()
    {
        return $this->hasMany(ScopeArticle::class, 'author_id');
    }
}

class PublishedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('status', 'published');
    }
}

class GlobalScopeArticle extends Model
{
    protected ?string $table = 'scope_articles';

    protected array $fillable = ['title', 'status', 'category', 'views', 'is_featured'];

    protected static function booted(): void
    {
        static::addGlobalScope(new PublishedScope());
    }
}
