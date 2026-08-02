<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\AuthEloquentBuilderCanTest;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use stdClass;

use function Hypervel\Coroutine\parallel;

class AuthEloquentBuilderCanTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate()->policy(Post::class, PostPolicy::class);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('query_aware_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->string('title');
            $table->boolean('is_public')->default(false);
            $table->boolean('published')->default(false);
        });

        DB::table('query_aware_posts')->insert([
            ['author_id' => 1, 'title' => 'one', 'is_public' => false, 'published' => false],
            ['author_id' => 1, 'title' => 'two', 'is_public' => true, 'published' => true],
            ['author_id' => 2, 'title' => 'three', 'is_public' => false, 'published' => false],
            ['author_id' => 2, 'title' => 'four', 'is_public' => true, 'published' => false],
        ]);
    }

    protected function gate(): GateContract
    {
        return $this->app->make(GateContract::class);
    }

    protected function setCurrentUser(?stdClass $user): void
    {
        /** @var AuthManager $auth */
        $auth = $this->app->make('auth');
        $auth->resolveUsersUsing(fn () => $user);
    }

    protected function user(int $id): stdClass
    {
        return (object) ['id' => $id];
    }

    public function testWhereCanUsesCurrentExplicitAndNullCurrentUser(): void
    {
        $currentUser = $this->user(1);
        $this->setCurrentUser($currentUser);

        $currentIds = Post::query()->whereCan('edit')->orderBy('id')->pluck('id')->all();
        $nullIds = Post::query()->whereCan('edit', null)->orderBy('id')->pluck('id')->all();
        $explicitIds = Post::query()->whereCan('edit', $this->user(2))->orderBy('id')->pluck('id')->all();

        $this->assertSame([1, 2], $currentIds);
        $this->assertSame($currentIds, $nullIds);
        $this->assertSame([3, 4], $explicitIds);
    }

    public function testModelStaticWhereCanForwardsToBuilderMacro(): void
    {
        $this->setCurrentUser($this->user(1));

        $staticIds = Post::whereCan('edit')->orderBy('id')->pluck('id')->all();
        $builderIds = Post::query()->whereCan('edit')->orderBy('id')->pluck('id')->all();

        $this->assertSame([1, 2], $staticIds);
        $this->assertSame($builderIds, $staticIds);
    }

    public function testWhereCanHandlesGuestEligibleAndIneligiblePolicies(): void
    {
        $this->setCurrentUser(null);

        $visibleIds = Post::query()->whereCan('view')->orderBy('id')->pluck('id')->all();
        $deletableIds = Post::query()->whereCan('delete')->orderBy('id')->pluck('id')->all();

        $this->assertSame([2, 4], $visibleIds);
        $this->assertSame([], $deletableIds);
    }

    public function testWhereCanAcceptsEnumAbilityAndChainsAsAnd(): void
    {
        $user = $this->user(1);

        $enumIds = Post::query()->whereCan(Ability::Edit, $user)->orderBy('id')->pluck('id')->all();
        $chainedIds = Post::query()
            ->whereCan('edit', $user)
            ->whereCan('publish', $user)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2], $enumIds);
        $this->assertSame([1], $chainedIds);
    }

    public function testWithCanAddsOneOrMultipleStrictBooleanAttributesAndKeepsModelColumns(): void
    {
        $user = $this->user(1);

        $single = Post::query()->withCan('edit', $user)->orderBy('id')->firstOrFail();
        $posts = Post::query()
            ->withCan(['edit', 'publish', 'share as shareable'], $user)
            ->orderBy('id')
            ->get();

        $this->assertSame('one', $single->title);
        $this->assertTrue($single->can_edit);
        $this->assertSame(
            [true, true, false, false],
            $posts->pluck('can_edit')->all(),
        );
        $this->assertSame(
            [true, false, true, true],
            $posts->pluck('can_publish')->all(),
        );
        $this->assertSame(
            [true, true, false, true],
            $posts->pluck('shareable')->all(),
        );

        foreach ($posts as $post) {
            $this->assertIsBool($post->can_edit);
            $this->assertIsBool($post->can_publish);
            $this->assertIsBool($post->shareable);
            $this->assertIsString($post->title);
        }
    }

    public function testWithCanGeneratesDashedCamelExplicitAndDottedAliases(): void
    {
        $user = $this->user(1);
        $dashed = Post::query()->withCan('edit-post', $user)->firstOrFail();
        $camel = Post::query()->withCan('editPost', $user)->firstOrFail();
        $explicit = Post::query()->withCan('edit as editable', $user)->firstOrFail();
        $this->gate()->before(
            fn (mixed $currentUser, string $ability): ?bool => $ability === 'reports.view' ? true : null,
        );
        $dotted = Post::query()->withCan('reports.view', $user)->firstOrFail();

        $this->assertTrue($dashed->can_edit_post);
        $this->assertTrue($camel->can_edit_post);
        $this->assertTrue($explicit->editable);
        $this->assertTrue($dotted->can_reports_view);
    }

    public function testWithCanAcceptsSixtyThreeByteExplicitAlias(): void
    {
        $alias = 'a' . str_repeat('b', 62);

        $post = Post::query()
            ->withCan('edit as ' . $alias, $this->user(1))
            ->firstOrFail();

        $this->assertTrue($post->{$alias});
    }

    public function testWithCanRejectsInvalidOrOverlongExplicitAlias(): void
    {
        try {
            Post::query()->withCan('edit as invalid-alias', $this->user(1));
            $this->fail('Expected an invalid alias exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('contain only letters, numbers, and underscores', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('may not exceed 63 bytes');

        Post::query()->withCan('edit as ' . str_repeat('a', 64), $this->user(1));
    }

    public function testWithCanRejectsOverlongGeneratedAliasWithExplicitAliasGuidance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Provide a shorter explicit alias using [ability as alias].');

        Post::query()->withCan(str_repeat('a', 60), $this->user(1));
    }

    public function testWithCanRejectsDuplicateAliasesBeforeMutatingBuilder(): void
    {
        $query = Post::query();
        $columns = $query->getQuery()->columns;
        $casts = $query->getModel()->getCasts();

        try {
            $query->withCan(['edit-post', 'editPost'], $this->user(1));
            $this->fail('Expected a duplicate alias exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Provide distinct explicit aliases', $exception->getMessage());
        }

        $this->assertSame($columns, $query->getQuery()->columns);
        $this->assertSame($casts, $query->getModel()->getCasts());
    }

    public function testWithCanEmptyListReturnsSameUntouchedBuilder(): void
    {
        $query = Post::query();
        $columns = $query->getQuery()->columns;
        $casts = $query->getModel()->getCasts();

        $result = $query->withCan([]);

        $this->assertSame($query, $result);
        $this->assertSame($columns, $query->getQuery()->columns);
        $this->assertSame($casts, $query->getModel()->getCasts());
    }

    public function testScopeOnlyOrPolicyAnnotatesOnlyAuthorizedRows(): void
    {
        $posts = Post::query()
            ->withCan('share', $this->user(1))
            ->orderBy('id')
            ->get();

        $this->assertSame([true, true, false, true], $posts->pluck('can_share')->all());
    }

    public function testCurrentUserQueriesAreIsolatedBetweenCoroutines(): void
    {
        $results = parallel([
            'first' => function (): array {
                $this->setCurrentUser($this->user(1));
                usleep(5000);

                return [
                    'filtered' => Post::query()->whereCan('edit')->orderBy('id')->pluck('id')->all(),
                    'annotated' => Post::query()->withCan('edit')->orderBy('id')->get()->pluck('can_edit')->all(),
                ];
            },
            'second' => function (): array {
                $this->setCurrentUser($this->user(2));
                usleep(5000);

                return [
                    'filtered' => Post::query()->whereCan('edit')->orderBy('id')->pluck('id')->all(),
                    'annotated' => Post::query()->withCan('edit')->orderBy('id')->get()->pluck('can_edit')->all(),
                ];
            },
        ]);

        $this->assertSame([1, 2], $results['first']['filtered']);
        $this->assertSame([true, true, false, false], $results['first']['annotated']);
        $this->assertSame([3, 4], $results['second']['filtered']);
        $this->assertSame([false, false, true, true], $results['second']['annotated']);
    }
}

enum Ability: string
{
    case Edit = 'edit';
}

class Post extends Model
{
    protected ?string $table = 'query_aware_posts';

    protected array $casts = [
        'is_public' => 'bool',
        'published' => 'bool',
    ];

    public bool $timestamps = false;
}

class PostPolicy
{
    public function edit(stdClass $user, Post $post): bool
    {
        return $post->author_id === $user->id;
    }

    public function editScope(stdClass $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }

    public function editPost(stdClass $user, Post $post): bool
    {
        return $this->edit($user, $post);
    }

    public function editPostScope(stdClass $user, Builder $query): Builder
    {
        return $this->editScope($user, $query);
    }

    public function publish(stdClass $user, Post $post): bool
    {
        return ! $post->published;
    }

    public function publishScope(stdClass $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('published'), false);
    }

    public function share(stdClass $user, Post $post): bool
    {
        return $post->author_id === $user->id || $post->is_public;
    }

    public function shareScope(stdClass $user, Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('author_id'), $user->id)
            ->orWhere($query->qualifyColumn('is_public'), true);
    }

    public function view(?stdClass $user, Post $post): bool
    {
        return $post->is_public || $post->author_id === $user?->id;
    }

    public function viewScope(?stdClass $user, Builder $query): Builder
    {
        return $query->when(
            $user !== null,
            fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->where($query->qualifyColumn('author_id'), $user->id)
                    ->orWhere($query->qualifyColumn('is_public'), true),
            ),
            fn (Builder $query): Builder => $query->where($query->qualifyColumn('is_public'), true),
        );
    }

    public function delete(stdClass $user, Post $post): bool
    {
        return $post->author_id === $user->id;
    }

    public function deleteScope(stdClass $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }
}
