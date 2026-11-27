<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Error;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Fixtures\LegacyPost;
use Hypervel\Tests\Foundation\Fixtures\Models\BlogPost;
use PHPUnit\Framework\Attributes\DataProvider;

class FoundationFormRequestModelTest extends TestCase
{
    use RefreshDatabase;

    protected function resolveApplication(): ApplicationContract
    {
        return FormRequestModelTestApplication::configure($this->getApplicationBasePath())
            ->withMiddleware()
            ->create();
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id');
            $table->string('slug')->unique();
        });
    }

    public function testModelCanBeRetrievedUsingAnExplicitClass(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['article_id' => $post->id]);

        $this->assertTrue($request->model('article_id', BlogPost::class)->is($post));
    }

    public function testModelClassCanBeDiscoveredFromTheInputKey(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $this->assertTrue($request->model('blog_post_id')->is($post));
    }

    public function testDiscoveryPrefersTheModelsNamespaceWhenBothClassesExist(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $this->assertSame(BlogPost::class, $request->model('blog_post_id')::class);
    }

    public function testDiscoveryFallsBackToTheApplicationNamespace(): void
    {
        $post = LegacyPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['legacy_post_id' => $post->id]);
        $request->setContainer($this->app);

        $model = $request->model('legacy_post_id');

        $this->assertInstanceOf(LegacyPost::class, $model);
        $this->assertTrue($model->is($post));
    }

    public function testCallbackCanBePassedAsTheSecondArgument(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $model = $request->model('blog_post_id', function (Builder $query) {
            $query->where('account_id', 1);
        });

        $this->assertTrue($model->is($post));

        $this->assertNull($request->model('blog_post_id', function (Builder $query) {
            $query->where('account_id', 2);
        }));
    }

    public function testCallbackCanBePassedWithAnExplicitClass(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['article_id' => $post->id]);

        $model = $request->model('article_id', BlogPost::class, function (Builder $query) {
            $query->where('account_id', 1);
        });

        $this->assertTrue($model->is($post));

        $this->assertNull($request->model('article_id', BlogPost::class, function (Builder $query) {
            $query->where('account_id', 2);
        }));
    }

    public function testCallbackCanBePassedWithANullClass(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $this->assertNull($request->model('blog_post_id', null, function (Builder $query) {
            $query->where('account_id', 2);
        }));
    }

    #[DataProvider('emptyInputProvider')]
    public function testEmptyInputReturnsNullWithoutResolvingAClassOrCallingTheCallback(array $input): void
    {
        $request = FormRequest::create('/', 'POST', $input);

        $this->assertNull($request->model('blog_post_id', function () {
            $this->fail('The callback should not be called for empty input.');
        }));
    }

    public static function emptyInputProvider(): array
    {
        return [
            [[]],
            [['blog_post_id' => null]],
            [['blog_post_id' => '']],
            [['blog_post_id' => '   ']],
        ];
    }

    public function testArrayInputReturnsNull(): void
    {
        $first = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $second = BlogPost::create(['account_id' => 1, 'slug' => 'second']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => [$first->id, $second->id]]);
        $request->setContainer($this->app);

        $this->assertNull($request->model('blog_post_id'));
    }

    public function testArrayInputReturnsNullForStringKeys(): void
    {
        SlugKeyedBlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['slug' => ['first']]);

        $this->assertNull($request->model('slug', SlugKeyedBlogPost::class));
    }

    public function testMissingRecordReturnsNull(): void
    {
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => 1]);
        $request->setContainer($this->app);

        $this->assertNull($request->model('blog_post_id'));
    }

    public function testZeroIsAValidIdentifier(): void
    {
        $post = BlogPost::create(['id' => 0, 'account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => 0]);
        $request->setContainer($this->app);

        $this->assertTrue($request->model('blog_post_id')->is($post));
    }

    public function testGlobalScopesAreAppliedAndCanBeRemovedByTheCallback(): void
    {
        $post = BlogPost::create(['account_id' => 2, 'slug' => 'first']);
        BlogPost::addGlobalScope('account', function (Builder $query) {
            $query->where('account_id', 1);
        });

        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $this->assertNull($request->model('blog_post_id'));
        $this->assertTrue($request->model('blog_post_id', function (Builder $query) {
            $query->withoutGlobalScope('account');
        })->is($post));
    }

    public function testCustomStringPrimaryKeyCanBeUsedWithNestedInput(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['article' => ['slug' => $post->slug]]);

        $model = $request->model('article.slug', SlugKeyedBlogPost::class);

        $this->assertInstanceOf(SlugKeyedBlogPost::class, $model);
        $this->assertSame($post->slug, $model->getKey());
    }

    public function testUnknownModelClassThrowsAnError(): void
    {
        $request = FormRequest::create('/', 'POST', ['unknown_id' => 1]);
        $request->setContainer($this->app);

        $this->expectException(Error::class);
        $this->expectExceptionMessageIs('Class "Hypervel\Tests\Foundation\Fixtures\Unknown" not found');

        $request->model('unknown_id');
    }

    public function testModelOrFailCanDiscoverTheModelClass(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $this->assertTrue($request->modelOrFail('blog_post_id')->is($post));
    }

    public function testModelOrFailSupportsAnExplicitClassAndCallback(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['article_id' => $post->id]);

        $model = $request->modelOrFail('article_id', BlogPost::class, function (Builder $query) {
            $query->where('account_id', 1);
        });

        $this->assertTrue($model->is($post));
    }

    public function testModelOrFailSupportsACallbackAsTheSecondArgument(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $model = $request->modelOrFail('blog_post_id', function (Builder $query) {
            $query->where('account_id', 1);
        });

        $this->assertTrue($model->is($post));
    }

    public function testModelOrFailThrowsWhenTheRecordDoesNotExist(): void
    {
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => 123]);
        $request->setContainer($this->app);

        $this->expectExceptionObject((new ModelNotFoundException)->setModel(BlogPost::class));

        $request->modelOrFail('blog_post_id');
    }

    public function testModelOrFailThrowsWhenTheCallbackExcludesTheRecord(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $this->expectExceptionObject((new ModelNotFoundException)->setModel(BlogPost::class));

        $request->modelOrFail('blog_post_id', function (Builder $query) {
            $query->where('account_id', 2);
        });
    }

    #[DataProvider('emptyInputProvider')]
    public function testModelOrFailThrowsForEmptyInput(array $input): void
    {
        BlogPost::create(['id' => 0, 'account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', $input);

        $this->expectExceptionObject((new ModelNotFoundException)->setModel(BlogPost::class));

        $request->modelOrFail('blog_post_id', BlogPost::class, function (Builder $query) {
            $query->where('account_id', 1);
        });
    }

    public function testModelOrFailThrowsForArrayInput(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => [$post->id]]);

        $this->expectExceptionObject((new ModelNotFoundException)->setModel(BlogPost::class));

        $request->modelOrFail('blog_post_id', BlogPost::class);
    }

    public function testModelOrFailCanRetrieveAZeroIdentifier(): void
    {
        $post = BlogPost::create(['id' => 0, 'account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => 0]);

        $this->assertTrue($request->modelOrFail('blog_post_id', BlogPost::class)->is($post));
    }

    public function testModelsCanBeRetrievedUsingAnExplicitClass(): void
    {
        $first = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $second = BlogPost::create(['account_id' => 1, 'slug' => 'second']);
        BlogPost::create(['account_id' => 1, 'slug' => 'third']);

        $request = FormRequest::create('/', 'POST', [
            'article_ids' => [$first->id, $second->id, $first->id, 999],
        ]);

        $models = $request->models('article_ids', BlogPost::class);

        $this->assertInstanceOf(Collection::class, $models);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $models->modelKeys());
    }

    #[DataProvider('collectionKeyProvider')]
    public function testModelsCanDiscoverTheModelClass(string $key): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', [$key => [$post->id]]);
        $request->setContainer($this->app);

        $models = $request->models($key);

        $this->assertSame(BlogPost::class, $models->first()::class);
        $this->assertSame([$post->id], $models->modelKeys());
    }

    public static function collectionKeyProvider(): array
    {
        return [
            ['blog_post_ids'],
            ['blog_posts'],
        ];
    }

    public function testModelsCanDiscoverAModelInTheApplicationNamespace(): void
    {
        $post = LegacyPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['legacy_post_ids' => [$post->id]]);
        $request->setContainer($this->app);

        $this->assertInstanceOf(LegacyPost::class, $request->models('legacy_post_ids')->first());
    }

    public function testModelsSupportsACallbackAsTheSecondArgument(): void
    {
        $first = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $second = BlogPost::create(['account_id' => 2, 'slug' => 'second']);
        $request = FormRequest::create('/', 'POST', ['blog_post_ids' => [$first->id, $second->id]]);
        $request->setContainer($this->app);

        $models = $request->models('blog_post_ids', function (Builder $query) {
            $query->where('account_id', 1);
        });

        $this->assertSame([$first->id], $models->modelKeys());
    }

    public function testModelsSupportsAnExplicitClassAndCallback(): void
    {
        $first = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $second = BlogPost::create(['account_id' => 2, 'slug' => 'second']);
        $request = FormRequest::create('/', 'POST', ['article_ids' => [$first->id, $second->id]]);

        $models = $request->models('article_ids', BlogPost::class, function (Builder $query) {
            $query->where('account_id', 2);
        });

        $this->assertSame([$second->id], $models->modelKeys());
    }

    public function testModelsSupportsANullClassAndCallback(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_ids' => [$post->id]]);
        $request->setContainer($this->app);

        $models = $request->models('blog_post_ids', null, function (Builder $query) {
            $query->where('account_id', 2);
        });

        $this->assertTrue($models->isEmpty());
    }

    public function testModelsReturnsAnEmptyCollectionWithoutQueryingForMissingOrEmptyInput(): void
    {
        $request = FormRequest::create('/', 'POST', ['article_ids' => []]);
        DB::enableQueryLog();

        $this->assertTrue($request->models('article_ids', BlogPost::class)->isEmpty());
        $this->assertTrue($request->models('missing_ids', BlogPost::class)->isEmpty());
        $this->assertSame([], DB::getQueryLog());
    }

    public function testModelsPreservesCustomCollectionsForEmptyInput(): void
    {
        $request = FormRequest::create('/', 'POST', ['article_ids' => []]);

        $this->assertInstanceOf(
            BlogPostCollection::class,
            $request->models('article_ids', CollectedBlogPost::class)
        );
    }

    public function testModelsUsesCustomPrimaryKeysAndNestedInput(): void
    {
        $post = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['articles' => ['slugs' => [$post->slug]]]);

        $models = $request->models('articles.slugs', SlugKeyedBlogPost::class);

        $this->assertSame([$post->slug], $models->modelKeys());
    }

    public function testModelGroupsCallbackConditionsBeforeApplyingTheIdentifier(): void
    {
        BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $post = BlogPost::create(['account_id' => 2, 'slug' => 'second']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => $post->id]);
        $request->setContainer($this->app);

        $model = $request->model('blog_post_id', function (Builder $query) {
            $query->where('account_id', 1)->orWhere('account_id', 2);
        });

        $this->assertTrue($model->is($post));
    }

    public function testModelOrFailGroupsCallbackConditionsBeforeApplyingTheIdentifier(): void
    {
        BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $request = FormRequest::create('/', 'POST', ['blog_post_id' => 999]);
        $request->setContainer($this->app);

        $this->expectException(ModelNotFoundException::class);

        $request->modelOrFail('blog_post_id', function (Builder $query) {
            $query->where('account_id', 1)->orWhere('account_id', 2);
        });
    }

    public function testModelsGroupsCallbackConditionsBeforeApplyingTheIdentifiers(): void
    {
        BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $post = BlogPost::create(['account_id' => 2, 'slug' => 'second']);
        $request = FormRequest::create('/', 'POST', ['blog_post_ids' => [$post->id]]);
        $request->setContainer($this->app);

        $models = $request->models('blog_post_ids', function (Builder $query) {
            $query->where('account_id', 1)->orWhere('account_id', 2);
        });

        $this->assertSame([$post->id], $models->modelKeys());
    }

    public function testModelsIgnoresNestedArrayInputForIntegerKeys(): void
    {
        $first = BlogPost::create(['account_id' => 1, 'slug' => 'first']);
        $second = BlogPost::create(['account_id' => 1, 'slug' => 'second']);

        DB::enableQueryLog();

        $request = FormRequest::create('/', 'POST', ['blog_post_ids' => [[$first->id], [$second->id]]]);

        $this->assertTrue($request->models('blog_post_ids', BlogPost::class)->isEmpty());
        $this->assertSame([], DB::getQueryLog());

        $request = FormRequest::create('/', 'POST', ['blog_post_ids' => [$first->id, [$second->id]]]);

        $this->assertSame([$first->id], $request->models('blog_post_ids', BlogPost::class)->modelKeys());
    }

    public function testModelsIgnoresNestedArrayInputForStringKeys(): void
    {
        SlugKeyedBlogPost::create(['account_id' => 1, 'slug' => 'first']);
        SlugKeyedBlogPost::create(['account_id' => 1, 'slug' => 'second']);

        $request = FormRequest::create('/', 'POST', ['slugs' => [['first']]]);
        $this->assertTrue($request->models('slugs', SlugKeyedBlogPost::class)->isEmpty());

        $request = FormRequest::create('/', 'POST', ['slugs' => [['first', 'second']]]);
        $this->assertTrue($request->models('slugs', SlugKeyedBlogPost::class)->isEmpty());

        $request = FormRequest::create('/', 'POST', ['slugs' => ['first', ['second']]]);
        $this->assertSame(['first'], $request->models('slugs', SlugKeyedBlogPost::class)->modelKeys());
    }
}

class FormRequestModelTestApplication extends Application
{
    /**
     * Get the application namespace.
     */
    public function getNamespace(): string
    {
        return 'Hypervel\Tests\Foundation\Fixtures\\';
    }
}

class SlugKeyedBlogPost extends BlogPost
{
    protected ?string $table = 'blog_posts';

    protected ?string $primaryKey = 'slug';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}

class CollectedBlogPost extends BlogPost
{
    protected ?string $table = 'blog_posts';

    /**
     * Create a new Eloquent collection instance.
     */
    public function newCollection(array $models = []): Collection
    {
        return new BlogPostCollection($models);
    }
}

class BlogPostCollection extends Collection
{
}
