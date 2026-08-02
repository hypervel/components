<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\AuthQueryAwarePolicyTest;

use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class AuthQueryAwarePolicyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(GateContract::class)->policy(Post::class, PostPolicy::class);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('auth_query_posts', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('owner_id')->nullable();
            $table->string('title');
            $table->softDeletes();
        });

        DB::table('auth_query_posts')->insert([
            ['id' => 'owned', 'owner_id' => "user'o", 'title' => 'Owned', 'deleted_at' => null],
            ['id' => 'other', 'owner_id' => 'other-user', 'title' => 'Other', 'deleted_at' => null],
            ['id' => 'nullable', 'owner_id' => null, 'title' => 'Nullable', 'deleted_at' => null],
        ]);
    }

    protected function user(string $id = "user'o"): User
    {
        return new User($id);
    }

    public function testSelectOnlyWhereCanPreservesQuotedBindings(): void
    {
        $query = Post::query()->whereCan('manage', $this->user())->orderBy('id');

        $this->assertContains("user'o", $query->getQuery()->getBindings());
        $this->assertSame(['owned'], $query->pluck('id')->all());
    }

    public function testScopeAndSelectAnnotationsHydrateStrictBooleansAndPreserveAttributes(): void
    {
        $posts = Post::query()
            ->withCan(['edit', 'manage'], $this->user())
            ->get()
            ->keyBy('id');

        $this->assertSame('Owned', $posts['owned']->title);
        $this->assertTrue($posts['owned']->can_edit);
        $this->assertTrue($posts['owned']->can_manage);
        $this->assertFalse($posts['other']->can_edit);
        $this->assertFalse($posts['other']->can_manage);
        $this->assertFalse($posts['nullable']->can_edit);
        $this->assertFalse($posts['nullable']->can_manage);

        foreach ($posts as $post) {
            $this->assertIsBool($post->can_edit);
            $this->assertIsBool($post->can_manage);
        }
    }

    public function testOuterWithTrashedOwnsVisibilityAndInnerScopeExtensionsRemainAvailable(): void
    {
        $post = Post::create([
            'id' => 'trashed',
            'owner_id' => "user'o",
            'title' => 'Trashed',
        ]);
        $post->delete();

        $annotatedPost = Post::query()
            ->withTrashed()
            ->withCan(['edit', 'restore'], $this->user())
            ->findOrFail('trashed');

        $this->assertTrue($annotatedPost->trashed());
        $this->assertTrue($annotatedPost->can_edit);
        $this->assertTrue($annotatedPost->can_restore);
    }

    public function testPolicyBeforeResultsHydrateAsTrueAndFalseLiterals(): void
    {
        $post = Post::query()
            ->withCan(['policy-before-allowed', 'policy-before-denied'], $this->user())
            ->findOrFail('owned');

        $this->assertTrue($post->can_policy_before_allowed);
        $this->assertFalse($post->can_policy_before_denied);
    }
}

class User
{
    public function __construct(public string $id)
    {
    }
}

class Post extends Model
{
    use SoftDeletes;

    protected ?string $table = 'auth_query_posts';

    protected array $guarded = [];

    protected string $keyType = 'string';

    public bool $incrementing = false;

    public bool $timestamps = false;
}

class PostPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return match ($ability) {
            'policy-before-allowed' => true,
            'policy-before-denied' => false,
            default => null,
        };
    }

    public function edit(User $user, Post $post): bool
    {
        return $post->owner_id === $user->id;
    }

    public function editScope(User $user, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('owner_id'), $user->id);
    }

    public function manage(User $user, Post $post): bool
    {
        return $post->owner_id === $user->id;
    }

    public function manageSelect(User $user, Builder $query): QueryBuilder
    {
        return $query->getQuery()->newQuery()->selectRaw(
            $query->qualifyColumn('owner_id') . ' = ?',
            [$user->id],
        );
    }

    public function restore(User $user, Post $post): bool
    {
        return $post->owner_id === $user->id;
    }

    public function restoreScope(User $user, Builder $query): Builder
    {
        $query->withTrashed();

        return $query->where($query->qualifyColumn('owner_id'), $user->id);
    }

    public function policyBeforeAllowedScope(User $user, Builder $query): Builder
    {
        return $query->whereRaw('0 = 1');
    }

    public function policyBeforeDeniedScope(User $user, Builder $query): Builder
    {
        return $query;
    }
}
