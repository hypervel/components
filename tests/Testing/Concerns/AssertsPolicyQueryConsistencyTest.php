<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns\AssertsPolicyQueryConsistencyTest;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Concerns\AssertsPolicyQueryConsistency;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;

class AssertsPolicyQueryConsistencyTest extends TestCase
{
    use AssertsPolicyQueryConsistency;
    use DatabaseMigrations;

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('consistency_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('author_id');
        });

        DB::table('consistency_posts')->insert([
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 2],
            ['author_id' => 2],
        ]);
    }

    protected function gate(): GateContract
    {
        return $this->app->make(GateContract::class);
    }

    /** @param class-string $policy */
    protected function registerPolicy(string $policy): void
    {
        $this->gate()->policy(ConsistencyPost::class, $policy);
    }

    protected function setCurrentUser(?stdClass $user): void
    {
        /** @var AuthManager $auth */
        $auth = $this->app->make('auth');
        $auth->resolveUsersUsing(fn () => $user);
    }

    protected function user(int $id, bool $isAdministrator = false): stdClass
    {
        return (object) ['id' => $id, 'is_admin' => $isAdministrator];
    }

    public function testWhereCanMatchesSelectOnlyPolicyForOwner(): void
    {
        $this->registerPolicy(SelectOnlyConsistencyPostPolicy::class);
        $user = $this->user(1);

        $posts = ConsistencyPost::all();

        $this->assertWhereCanMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testWhereCanMatchesSelectOnlyPolicyForAdministrator(): void
    {
        $this->registerPolicy(SelectOnlyConsistencyPostPolicy::class);
        $user = $this->user(99, true);

        $posts = ConsistencyPost::all();

        $this->assertWhereCanMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testWithCanMatchesScopeOnlyPolicyForCurrentUser(): void
    {
        $this->registerPolicy(ScopeOnlyConsistencyPostPolicy::class);
        $this->setCurrentUser($this->user(2));

        $posts = ConsistencyPost::all();

        $this->assertWithCanMatchesPolicy(
            ConsistencyAbility::Edit,
            ConsistencyPost::query(),
            $posts,
        );
    }

    public function testWithCanMatchesScopeOnlyPolicyForAdministrator(): void
    {
        $this->registerPolicy(ScopeOnlyConsistencyPostPolicy::class);
        $user = $this->user(99, true);

        $posts = ConsistencyPost::all();

        $this->assertWithCanMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testWhereCanMatchesPolicyWithBaseQueryConstraints(): void
    {
        $this->registerPolicy(ScopeOnlyConsistencyPostPolicy::class);
        $user = $this->user(1);

        $baseQuery = ConsistencyPost::where('id', '>=', 2);
        $posts = $baseQuery->get();

        $this->assertWhereCanMatchesPolicy(
            'edit',
            $baseQuery,
            $posts,
            $user,
        );
    }

    public function testWithCanMatchesPolicyWithCustomColumnName(): void
    {
        $this->registerPolicy(SelectOnlyConsistencyPostPolicy::class);
        $user = $this->user(1);

        $posts = ConsistencyPost::all();

        $this->assertWithCanMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
            'is_editable',
        );
    }

    public function testWhereCanAssertionFailsOnEmptyCollection(): void
    {
        $this->registerPolicy(ScopeOnlyConsistencyPostPolicy::class);

        $this->expectException(AssertionFailedError::class);

        $this->assertWhereCanMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            collect(),
            $this->user(1),
        );
    }

    public function testWithCanAssertionFailsOnEmptyCollection(): void
    {
        $this->registerPolicy(SelectOnlyConsistencyPostPolicy::class);

        $this->expectException(AssertionFailedError::class);

        $this->assertWithCanMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            collect(),
            $this->user(1),
        );
    }
}

enum ConsistencyAbility: string
{
    case Edit = 'edit';
}

class ConsistencyPost extends Model
{
    protected ?string $table = 'consistency_posts';

    public bool $timestamps = false;
}

class ScopeOnlyConsistencyPostPolicy
{
    public function edit(stdClass $user, ConsistencyPost $post): bool
    {
        return $user->is_admin || $post->author_id === $user->id;
    }

    public function editScope(stdClass $user, Builder $query): Builder
    {
        if ($user->is_admin) {
            return $query;
        }

        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }
}

class SelectOnlyConsistencyPostPolicy
{
    public function edit(stdClass $user, ConsistencyPost $post): bool
    {
        return $user->is_admin || $post->author_id === $user->id;
    }

    public function editSelect(stdClass $user, Builder $query): QueryBuilder
    {
        return $query->getQuery()->newQuery()->selectRaw(
            $user->is_admin
                ? 'true'
                : $query->qualifyColumn('author_id') . ' = ?',
            $user->is_admin ? [] : [$user->id],
        );
    }
}
