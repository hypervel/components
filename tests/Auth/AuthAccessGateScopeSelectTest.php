<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Auth\Fixtures\NoScopePostPolicy;
use Hypervel\Tests\Auth\Fixtures\ScopablePost;
use Hypervel\Tests\Auth\Fixtures\ScopablePostPolicy;
use Hypervel\Tests\Auth\Fixtures\ScopablePostPolicyWithBefore;
use Hypervel\Tests\Auth\Fixtures\ScopeOnlyPostPolicy;
use Mockery as m;
use RuntimeException;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class AuthAccessGateScopeSelectTest extends TestCase
{
    protected function getGate(bool $isAdmin = false): Gate
    {
        return new Gate($this->app, function () use ($isAdmin) {
            return (object) ['id' => 1, 'is_admin' => $isAdmin];
        });
    }

    protected function createMockQueryBuilder(): Builder
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn(new ScopablePost);
        $builder->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column) => 'posts.' . $column);

        return $builder;
    }

    // --- scope() tests ---

    public function testScopeAppliesPolicyScopeMethodToQuery()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('where')->with('posts.author_id', 1)->once()->andReturnSelf();

        $result = $gate->scope('edit', $builder);

        $this->assertSame($builder, $result);
    }

    public function testScopeWithAdminBypassesConstraints()
    {
        $gate = $this->getGate(isAdmin: true);
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldNotReceive('where');

        $result = $gate->scope('edit', $builder);

        $this->assertSame($builder, $result);
    }

    public function testScopeThrowsWhenPolicyHasNoScopeMethod()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, NoScopePostPolicy::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not define a \[editScope\] method/');

        $gate->scope('edit', $this->createMockQueryBuilder());
    }

    public function testScopeThrowsWhenNoPolicyExists()
    {
        $gate = $this->getGate();

        $this->expectException(RuntimeException::class);

        $gate->scope('edit', $this->createMockQueryBuilder());
    }

    public function testScopeNormalizesDashedAbility()
    {
        $gate = $this->getGate();

        $policy = new class {
            public function editPostScope(stdClass $user, Builder $query): Builder
            {
                return $query->where('author_id', $user->id);
            }
        };

        $gate->policy(ScopablePost::class, $policy::class);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('where')->with('author_id', 1)->once()->andReturnSelf();

        $gate->scope('edit-post', $builder);
    }

    public function testScopeResolvesUserFromUserResolver()
    {
        $gate = new Gate($this->app, fn () => (object) ['id' => 42, 'is_admin' => false]);
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('where')->with('posts.author_id', 42)->once()->andReturnSelf();

        $gate->scope('edit', $builder);
    }

    // --- select() tests ---

    public function testSelectReturnsSqlExpressionFromPolicy()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('posts.author_id = 1', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    public function testSelectWithAdminReturnsTrueExpression()
    {
        $gate = $this->getGate(isAdmin: true);
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('true', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    public function testSelectThrowsWhenPolicyHasNoSelectMethod()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopeOnlyPostPolicy::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not define a \[editSelect\] method/');

        $gate->select('edit', $this->createMockQueryBuilder());
    }

    public function testSelectThrowsWhenNoPolicyExists()
    {
        $gate = $this->getGate();

        $this->expectException(RuntimeException::class);

        $gate->select('edit', $this->createMockQueryBuilder());
    }

    public function testSelectAcceptsModelClassShorthand()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $result = $gate->select('edit', ScopablePost::class);

        $this->assertInstanceOf(Expression::class, $result);
    }

    public function testSelectAcceptsModelInstanceShorthand()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $result = $gate->select('edit', new ScopablePost);

        $this->assertInstanceOf(Expression::class, $result);
    }

    // --- before callback integration tests ---

    public function testScopeReturnsUnmodifiedQueryWhenBeforeCallbackAllows()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $gate->before(fn () => true);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldNotReceive('where');
        $builder->shouldNotReceive('whereRaw');

        $result = $gate->scope('edit', $builder);

        $this->assertSame($builder, $result);
    }

    public function testScopeReturnsNoRowsWhenBeforeCallbackDenies()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $gate->before(fn () => false);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('whereRaw')->with('0 = 1')->once()->andReturnSelf();

        $result = $gate->scope('edit', $builder);

        $this->assertSame($builder, $result);
    }

    public function testScopeFallsThroughToPolicyWhenBeforeCallbackReturnsNull()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $gate->before(fn () => null);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('where')->with('posts.author_id', 1)->once()->andReturnSelf();

        $gate->scope('edit', $builder);
    }

    public function testSelectReturnsTrueExpressionWhenBeforeCallbackAllows()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $gate->before(fn () => true);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('true', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    public function testSelectReturnsFalseExpressionWhenBeforeCallbackDenies()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $gate->before(fn () => false);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('false', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    public function testSelectFallsThroughToPolicyWhenBeforeCallbackReturnsNull()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $gate->before(fn () => null);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('posts.author_id = 1', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    public function testScopeDoesNotRunAfterCallbacks()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $afterCalled = false;
        $gate->after(function () use (&$afterCalled) {
            $afterCalled = true;
        });

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('where')->andReturnSelf();

        $gate->scope('edit', $builder);

        $this->assertFalse($afterCalled);
    }

    // --- policy before() integration tests ---

    public function testScopeRunsPolicyBeforeAndAllowsAdmin()
    {
        $gate = $this->getGate(isAdmin: true);
        $gate->policy(ScopablePost::class, ScopablePostPolicyWithBefore::class);

        $builder = $this->createMockQueryBuilder();
        // Policy before() returns true for admin — query should be unmodified
        $builder->shouldNotReceive('where');
        $builder->shouldNotReceive('whereRaw');

        $result = $gate->scope('edit', $builder);

        $this->assertSame($builder, $result);
    }

    public function testScopeRunsPolicyBeforeAndFallsThroughForNonAdmin()
    {
        $gate = $this->getGate(isAdmin: false);
        $gate->policy(ScopablePost::class, ScopablePostPolicyWithBefore::class);

        $builder = $this->createMockQueryBuilder();
        // Policy before() returns null for non-admin — editScope should be called
        $builder->shouldReceive('where')->with('posts.author_id', 1)->once()->andReturnSelf();

        $gate->scope('edit', $builder);
    }

    public function testSelectRunsPolicyBeforeAndAllowsAdmin()
    {
        $gate = $this->getGate(isAdmin: true);
        $gate->policy(ScopablePost::class, ScopablePostPolicyWithBefore::class);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('true', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    public function testSelectRunsPolicyBeforeAndFallsThroughForNonAdmin()
    {
        $gate = $this->getGate(isAdmin: false);
        $gate->policy(ScopablePost::class, ScopablePostPolicyWithBefore::class);

        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('posts.author_id = 1', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    // --- guest gating tests ---

    public function testScopeDeniesWhenGuestAndMethodDoesNotAllowGuests()
    {
        // Gate with null user (guest)
        $gate = new Gate($this->app, fn () => null);
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $builder = $this->createMockQueryBuilder();
        // editScope has non-nullable stdClass $user — should deny, not TypeError
        $builder->shouldReceive('whereRaw')->with('0 = 1')->once()->andReturnSelf();
        $builder->shouldNotReceive('where');

        $result = $gate->scope('edit', $builder);

        $this->assertSame($builder, $result);
    }

    public function testSelectDeniesWhenGuestAndMethodDoesNotAllowGuests()
    {
        // Gate with null user (guest)
        $gate = new Gate($this->app, fn () => null);
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        // editSelect has non-nullable stdClass $user — should deny, not TypeError
        $result = $gate->select('edit', $this->createMockQueryBuilder());

        $this->assertInstanceOf(Expression::class, $result);
        $this->assertSame('false', (string) $result->getValue(m::mock(\Hypervel\Database\Grammar::class)));
    }

    // --- edge cases ---

    public function testScopeWorksWithForUser()
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $otherUserGate = $gate->forUser((object) ['id' => 99, 'is_admin' => false]);

        $builder = $this->createMockQueryBuilder();
        $builder->shouldReceive('where')->with('posts.author_id', 99)->once()->andReturnSelf();

        $otherUserGate->scope('edit', $builder);
    }
}
