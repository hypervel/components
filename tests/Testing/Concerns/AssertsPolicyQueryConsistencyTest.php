<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns\AssertsPolicyQueryConsistencyTest;

use Hypervel\Auth\Access\Gate;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Expression;
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
        Schema::create('consistency_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id');
        });

        // Create posts: 3 owned by user 1, 2 owned by user 2
        DB::table('consistency_posts')->insert([
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 1],
            ['author_id' => 2],
            ['author_id' => 2],
        ]);
    }

    protected function getGate(mixed $user): Gate
    {
        $gate = new Gate($this->app, fn () => $user);
        $gate->policy(ConsistencyPost::class, ConsistencyPostPolicy::class);

        return $gate;
    }

    protected function setUpGate(mixed $user): void
    {
        $gate = $this->getGate($user);
        $this->app->instance(GateContract::class, $gate);
    }

    public function testScopeMatchesPolicyForOwner()
    {
        $user = (object) ['id' => 1, 'is_admin' => false];
        $this->setUpGate($user);

        $posts = ConsistencyPost::all();

        $this->assertScopeMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testScopeMatchesPolicyForAdmin()
    {
        $user = (object) ['id' => 99, 'is_admin' => true];
        $this->setUpGate($user);

        $posts = ConsistencyPost::all();

        $this->assertScopeMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testSelectMatchesPolicyForOwner()
    {
        $user = (object) ['id' => 2, 'is_admin' => false];
        $this->setUpGate($user);

        $posts = ConsistencyPost::all();

        $this->assertSelectMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testSelectMatchesPolicyForAdmin()
    {
        $user = (object) ['id' => 99, 'is_admin' => true];
        $this->setUpGate($user);

        $posts = ConsistencyPost::all();

        $this->assertSelectMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
        );
    }

    public function testScopeMatchesPolicyWithBaseQueryConstraints()
    {
        $user = (object) ['id' => 1, 'is_admin' => false];
        $this->setUpGate($user);

        // Only check against posts with author_id <= 2 (all posts, but via a constrained query)
        $baseQuery = ConsistencyPost::where('author_id', '<=', 2);
        $posts = $baseQuery->get();

        $this->assertScopeMatchesPolicy(
            'edit',
            ConsistencyPost::where('author_id', '<=', 2),
            $posts,
            $user,
        );
    }

    public function testSelectMatchesPolicyWithCustomColumnName()
    {
        $user = (object) ['id' => 1, 'is_admin' => false];
        $this->setUpGate($user);

        $posts = ConsistencyPost::all();

        $this->assertSelectMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            $posts,
            $user,
            'is_editable',
        );
    }

    public function testScopeAssertionFailsOnEmptyCollection()
    {
        $user = (object) ['id' => 1, 'is_admin' => false];
        $this->setUpGate($user);

        $this->expectException(AssertionFailedError::class);

        $this->assertScopeMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            collect(),
            $user,
        );
    }

    public function testSelectAssertionFailsOnEmptyCollection()
    {
        $user = (object) ['id' => 1, 'is_admin' => false];
        $this->setUpGate($user);

        $this->expectException(AssertionFailedError::class);

        $this->assertSelectMatchesPolicy(
            'edit',
            ConsistencyPost::query(),
            collect(),
            $user,
        );
    }
}

class ConsistencyPost extends Model
{
    protected ?string $table = 'consistency_posts';

    public bool $timestamps = false;
}

class ConsistencyPostPolicy
{
    /**
     * Per-instance PHP check.
     */
    public function edit(stdClass $user, ConsistencyPost $post): bool
    {
        return $user->is_admin || $post->author_id === $user->id;
    }

    /**
     * Query-level scope.
     */
    public function editScope(stdClass $user, Builder $query): Builder
    {
        if ($user->is_admin) {
            return $query;
        }

        return $query->where($query->qualifyColumn('author_id'), $user->id);
    }

    /**
     * Query-level select expression.
     */
    public function editSelect(stdClass $user, Builder $query): Expression
    {
        if ($user->is_admin) {
            return DB::raw('1');
        }

        return DB::raw($query->qualifyColumn('author_id') . ' = ' . (int) $user->id);
    }
}
