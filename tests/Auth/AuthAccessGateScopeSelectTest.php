<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Access\Gate;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Grammar;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Auth\Fixtures\AbilitiesEnum;
use Hypervel\Tests\Auth\Fixtures\NoQueryPostPolicy;
use Hypervel\Tests\Auth\Fixtures\ScopablePost;
use Hypervel\Tests\Auth\Fixtures\ScopablePostPolicy;
use Hypervel\Tests\Auth\Fixtures\ScopablePostPolicyWithBefore;
use Hypervel\Tests\Auth\Fixtures\ScopeOnlyPostPolicy;
use Hypervel\Tests\Auth\Fixtures\SelectOnlyPostPolicy;
use LogicException;
use RuntimeException;
use stdClass;

class AuthAccessGateScopeSelectTest extends TestCase
{
    protected function getGate(bool $isAdmin = false): Gate
    {
        return $this->gateForUser((object) [
            'id' => 1,
            'is_admin' => $isAdmin,
        ]);
    }

    protected function gateForUser(mixed $user): Gate
    {
        return new Gate($this->app, fn () => $user);
    }

    protected function createQueryBuilder(?Model $model = null): Builder
    {
        return ($model ?? new ScopablePost)->newQuery();
    }

    protected function registerPolicy(Gate $gate, object $policy, string $model = ScopablePost::class): void
    {
        $this->app->instance($policy::class, $policy);
        $gate->policy($model, $policy::class);
    }

    protected function expressionValue(ExpressionContract $expression, Grammar $grammar): string|int|float
    {
        return $expression->getValue($grammar);
    }

    public function testScopeAppliesPolicyScopeMethodToSameQuery(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $query = $this->createQueryBuilder();

        $result = $gate->scope('edit', $query);

        $this->assertSame($query, $result);
        $this->assertSame('select * from "posts" where ("posts"."author_id" = ?)', $query->toSql());
        $this->assertSame([1], $query->getBindings());
    }

    public function testSelectReturnsBindingSafeScalarQueryFromPolicy(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $selection = $gate->select('edit', $this->createQueryBuilder());

        $this->assertInstanceOf(QueryBuilder::class, $selection);
        $this->assertSame('select coalesce((select posts.author_id = ?), false)', $selection->toSql());
        $this->assertSame([1], $selection->getBindings());
    }

    public function testBackedAndUnitEnumAbilitiesAreNormalized(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function viewDashboardScope(stdClass $user, Builder $query): Builder
            {
                return $query->where('dashboard_user_id', $user->id);
            }

            public function editScope(stdClass $user, Builder $query): Builder
            {
                return $query->where('editor_id', $user->id);
            }
        };
        $this->registerPolicy($gate, $policy);

        $backedEnumQuery = $gate->scope(AbilitiesEnum::ViewDashboard, $this->createQueryBuilder());
        $unitEnumQuery = $gate->scope(QueryAwarePolicyAbility::Edit, $this->createQueryBuilder());

        $this->assertSame('select * from "posts" where ("dashboard_user_id" = ?)', $backedEnumQuery->toSql());
        $this->assertSame([1], $backedEnumQuery->getBindings());
        $this->assertSame('select * from "posts" where ("editor_id" = ?)', $unitEnumQuery->toSql());
        $this->assertSame([1], $unitEnumQuery->getBindings());
    }

    public function testProtectedNativeMethodFallsBackToPublicMethod(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            protected function editScope(stdClass $user, Builder $query): Builder
            {
                return $query->where('protected_scope', $user->id);
            }

            public function editSelect(stdClass $user, Builder $query): Expression
            {
                return new Expression($query->qualifyColumn('author_id') . ' = 1');
            }
        };
        $this->registerPolicy($gate, $policy);

        $query = $gate->scope('edit', $this->createQueryBuilder());

        $this->assertSame('select * from "posts" where ((posts.author_id = 1))', $query->toSql());
    }

    public function testMissingPolicyErrorNamesBothAcceptedMethods(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Policy [null] does not define an [editScope] or [editSelect] method.'
        );

        $this->getGate()->scope('edit', $this->createQueryBuilder());
    }

    public function testPolicyMissingBothQueryMethodsNamesBothAcceptedMethods(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, NoQueryPostPolicy::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'does not define an [editSelect] or [editScope] method.'
        );

        $gate->select('edit', $this->createQueryBuilder());
    }

    public function testScopePrefersNativeScopeMethod(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public int $scopeCalls = 0;

            public int $selectCalls = 0;

            public function editScope(stdClass $user, Builder $query): Builder
            {
                ++$this->scopeCalls;

                return $query->where('native_scope', $user->id);
            }

            public function editSelect(stdClass $user, Builder $query): Expression
            {
                ++$this->selectCalls;

                return new Expression('true');
            }
        };
        $this->registerPolicy($gate, $policy);

        $gate->scope('edit', $this->createQueryBuilder());

        $this->assertSame(1, $policy->scopeCalls);
        $this->assertSame(0, $policy->selectCalls);
    }

    public function testSelectPrefersNativeSelectMethod(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public int $scopeCalls = 0;

            public int $selectCalls = 0;

            public function editScope(stdClass $user, Builder $query): Builder
            {
                ++$this->scopeCalls;

                return $query->where('native_scope', $user->id);
            }

            public function editSelect(stdClass $user, Builder $query): Expression
            {
                ++$this->selectCalls;

                return new Expression('false');
            }
        };
        $this->registerPolicy($gate, $policy);

        $selection = $gate->select('edit', $this->createQueryBuilder());

        $this->assertSame(0, $policy->scopeCalls);
        $this->assertSame(1, $policy->selectCalls);
        $this->assertSame(
            'coalesce((false), false)',
            $this->expressionValue($selection, $this->createQueryBuilder()->getQuery()->getGrammar()),
        );
    }

    public function testGateAndPolicyBeforeCallbacksRunOncePerOperation(): void
    {
        $gate = $this->getGate();
        $gateBeforeCalls = 0;
        $gate->before(function () use (&$gateBeforeCalls): null {
            ++$gateBeforeCalls;

            return null;
        });
        $policy = new class {
            public int $beforeCalls = 0;

            public function before(stdClass $user, string $ability): ?bool
            {
                ++$this->beforeCalls;

                return null;
            }

            public function editScope(stdClass $user, Builder $query): Builder
            {
                return $query->where('author_id', $user->id);
            }

            public function editSelect(stdClass $user, Builder $query): Expression
            {
                return new Expression('true');
            }
        };
        $this->registerPolicy($gate, $policy);

        $gate->scope('edit', $this->createQueryBuilder());
        $gate->select('edit', $this->createQueryBuilder());

        $this->assertSame(2, $gateBeforeCalls);
        $this->assertSame(2, $policy->beforeCalls);
    }

    public function testQueryOperationsDoNotRunAfterCallbacks(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);
        $afterCalls = 0;
        $gate->after(function () use (&$afterCalls): void {
            ++$afterCalls;
        });

        $gate->scope('edit', $this->createQueryBuilder());
        $gate->select('edit', $this->createQueryBuilder());

        $this->assertSame(0, $afterCalls);
    }

    public function testGuestIneligibleNativeScopeDeniesWithoutSelectFallback(): void
    {
        $gate = $this->gateForUser(null);
        $policy = new class {
            public int $selectCalls = 0;

            public function editScope(stdClass $user, Builder $query): Builder
            {
                return $query;
            }

            public function editSelect(?stdClass $user, Builder $query): Expression
            {
                ++$this->selectCalls;

                return new Expression('true');
            }
        };
        $this->registerPolicy($gate, $policy);

        $query = $this->createQueryBuilder()
            ->where('tenant_id', 10)
            ->orWhere('is_public', true);

        $gate->scope('edit', $query);

        $this->assertSame(
            'select * from "posts" where ("tenant_id" = ? or "is_public" = ?) and (0 = 1)',
            $query->toSql(),
        );
        $this->assertSame([10, true], $query->getBindings());
        $this->assertSame(0, $policy->selectCalls);
    }

    public function testGuestIneligibleNativeSelectDeniesWithoutScopeFallback(): void
    {
        $gate = $this->gateForUser(null);
        $policy = new class {
            public int $scopeCalls = 0;

            public function editSelect(stdClass $user, Builder $query): Expression
            {
                return new Expression('true');
            }

            public function editScope(?stdClass $user, Builder $query): Builder
            {
                ++$this->scopeCalls;

                return $query;
            }
        };
        $this->registerPolicy($gate, $policy);
        $query = $this->createQueryBuilder();

        $selection = $gate->select('edit', $query);

        $this->assertSame('false', $this->expressionValue($selection, $query->getQuery()->getGrammar()));
        $this->assertSame(0, $policy->scopeCalls);
    }

    public function testGuestEligibleNativeMethodsAreCalled(): void
    {
        $gate = $this->gateForUser(null);
        $policy = new class {
            public function editScope(?stdClass $user, Builder $query): Builder
            {
                return $query->whereRaw($user === null ? '1 = 1' : '0 = 1');
            }

            public function editSelect(?stdClass $user, Builder $query): Expression
            {
                return new Expression($user === null ? 'true' : 'false');
            }
        };
        $this->registerPolicy($gate, $policy);
        $query = $this->createQueryBuilder();

        $gate->scope('edit', $query);
        $selection = $gate->select('edit', $this->createQueryBuilder());

        $this->assertSame('select * from "posts" where (1 = 1)', $query->toSql());
        $this->assertSame(
            'coalesce((true), false)',
            $this->expressionValue($selection, $query->getQuery()->getGrammar()),
        );
    }

    public function testSelectDerivesCorrelatedExistsFromScopeOnlyPolicy(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopeOnlyPostPolicy::class);
        $query = $this->createQueryBuilder();
        $alias = 'hypervel_reserved_' . hash('xxh128', ScopablePost::class . "\0edit");

        $selection = $gate->select('edit', $query);

        $this->assertInstanceOf(QueryBuilder::class, $selection);
        $this->assertSame(
            'select exists (select * from "posts" as "' . $alias . '" where ("' . $alias
            . '"."author_id" = ?) and "' . $alias . '"."id" = "posts"."id")',
            $selection->toSql(),
        );
        $this->assertSame([1], $selection->getBindings());
        $this->assertStringNotContainsString('coalesce', $selection->toSql());
    }

    public function testScopeGroupsCallerOrBeforeBaseQuerySelectionFallbackWithBindings(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, SelectOnlyPostPolicy::class);
        $query = $this->createQueryBuilder()
            ->where('tenant_id', 10)
            ->orWhere('is_public', true);

        $gate->scope('edit', $query);

        $this->assertSame(
            'select * from "posts" where ("tenant_id" = ? or "is_public" = ?) and ((select posts.author_id = ?))',
            $query->toSql(),
        );
        $this->assertSame([10, true, 1], $query->getBindings());
    }

    public function testScopeGroupsCallerOrAndParenthesizesCompoundExpressionSelectionFallback(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editSelect(stdClass $user, Builder $query): Expression
            {
                return new Expression(
                    $query->qualifyColumn('author_id') . ' = 1 OR '
                    . $query->qualifyColumn('is_public') . ' = true'
                );
            }
        };
        $this->registerPolicy($gate, $policy);
        $query = $this->createQueryBuilder()
            ->where('tenant_id', 10)
            ->orWhere('is_featured', true);

        $gate->scope('edit', $query);

        $this->assertSame(
            'select * from "posts" where ("tenant_id" = ? or "is_featured" = ?) and ((posts.author_id = 1 OR posts.is_public = true))',
            $query->toSql(),
        );
        $this->assertSame([10, true], $query->getBindings());
    }

    public function testNativeScopeGroupsOrConditionsAgainstExistingConstraint(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editScope(stdClass $user, Builder $query): Builder
            {
                return $query
                    ->where($query->qualifyColumn('author_id'), $user->id)
                    ->orWhere($query->qualifyColumn('is_public'), true);
            }
        };
        $this->registerPolicy($gate, $policy);
        $query = $this->createQueryBuilder()
            ->where('tenant_id', 10)
            ->orWhere('is_featured', true);

        $gate->scope('edit', $query);

        $this->assertSame(
            'select * from "posts" where ("tenant_id" = ? or "is_featured" = ?) and ("posts"."author_id" = ? or "posts"."is_public" = ?)',
            $query->toSql(),
        );
        $this->assertSame([10, true, 1, true], $query->getBindings());
    }

    public function testDerivedScopeGroupsEveryOrBranchBeforeCorrelation(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editScope(stdClass $user, Builder $query): Builder
            {
                return $query
                    ->where($query->qualifyColumn('author_id'), $user->id)
                    ->orWhere($query->qualifyColumn('is_public'), true);
            }
        };
        $this->registerPolicy($gate, $policy);
        $alias = 'hypervel_reserved_' . hash('xxh128', ScopablePost::class . "\0edit");

        $selection = $gate->select('edit', $this->createQueryBuilder());

        $this->assertSame(
            'select exists (select * from "posts" as "' . $alias . '" where ("' . $alias
            . '"."author_id" = ? or "' . $alias . '"."is_public" = ?) and "' . $alias
            . '"."id" = "posts"."id")',
            $selection->toSql(),
        );
        $this->assertSame([1, true], $selection->getBindings());
    }

    public function testQuoteContainingIdentifierRemainsABinding(): void
    {
        $gate = $this->gateForUser((object) [
            'id' => "O'Reilly",
            'is_admin' => false,
        ]);
        $gate->policy(ScopablePost::class, SelectOnlyPostPolicy::class);

        $query = $gate->scope('edit', $this->createQueryBuilder());

        $this->assertSame('select * from "posts" where ((select posts.author_id = ?))', $query->toSql());
        $this->assertSame(["O'Reilly"], $query->getBindings());
        $this->assertStringNotContainsString("O'Reilly", $query->toSql());
    }

    public function testExplicitExpressionSelectionCoalescesNullToFalse(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editSelect(stdClass $user, Builder $query): Expression
            {
                return new Expression('null');
            }
        };
        $this->registerPolicy($gate, $policy);
        $query = $this->createQueryBuilder();

        $selection = $gate->select('edit', $query);

        $this->assertSame(
            'coalesce((null), false)',
            $this->expressionValue($selection, $query->getQuery()->getGrammar()),
        );
    }

    public function testExplicitBaseQuerySelectionCoalescesNullToFalse(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editSelect(stdClass $user, Builder $query): QueryBuilder
            {
                return $query->getQuery()->newQuery()->selectRaw('null');
            }
        };
        $this->registerPolicy($gate, $policy);

        $selection = $gate->select('edit', $this->createQueryBuilder());

        $this->assertSame('select coalesce((select null), false)', $selection->toSql());
        $this->assertSame([], $selection->getBindings());
    }

    public function testGateBeforeLiteralsAreNotWrappedInCoalesce(): void
    {
        $allowingGate = $this->getGate();
        $allowingGate->before(fn () => true);
        $denyingGate = $this->getGate();
        $denyingGate->before(fn () => false);
        $query = $this->createQueryBuilder();

        $allowed = $allowingGate->select('edit', $query);
        $denied = $denyingGate->select('edit', $query);

        $this->assertSame('true', $this->expressionValue($allowed, $query->getQuery()->getGrammar()));
        $this->assertSame('false', $this->expressionValue($denied, $query->getQuery()->getGrammar()));
    }

    public function testScopeRejectsNullReturnValue(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editScope(stdClass $user, Builder $query): mixed
            {
                return null;
            }
        };
        $this->registerPolicy($gate, $policy);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return the same Eloquent builder instance it receives');

        $gate->scope('edit', $this->createQueryBuilder());
    }

    public function testScopeRejectsReplacementBuilder(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public function editScope(stdClass $user, Builder $query): Builder
            {
                return $query->getModel()->newQuery();
            }
        };
        $this->registerPolicy($gate, $policy);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return the same Eloquent builder instance it receives');

        $gate->scope('edit', $this->createQueryBuilder());
    }

    public function testDerivedScopeUsesSeparateAliasedModelWithoutChangingOuterModel(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public ?Model $model = null;

            public ?string $qualifiedColumn = null;

            public function editScope(stdClass $user, Builder $query): Builder
            {
                $this->model = $query->getModel();
                $this->qualifiedColumn = $query->qualifyColumn('author_id');

                return $query->where($this->qualifiedColumn, $user->id);
            }
        };
        $this->registerPolicy($gate, $policy);
        $query = $this->createQueryBuilder();
        $outerModel = $query->getModel();
        $alias = 'hypervel_reserved_' . hash('xxh128', ScopablePost::class . "\0edit");

        $gate->select('edit', $query);

        $this->assertNotSame($outerModel, $policy->model);
        $this->assertSame('posts', $outerModel->getTable());
        $this->assertSame($alias, $policy->model?->getTable());
        $this->assertSame($alias . '.author_id', $policy->qualifiedColumn);
    }

    public function testDerivedScopeRetainsGlobalScopeExtensionsWithoutConstraints(): void
    {
        $gate = $this->getGate();
        $policy = new class {
            public bool $extensionCalled = false;

            public function editScope(stdClass $user, Builder $query): Builder
            {
                $query->withTrashed();
                $this->extensionCalled = true;

                return $query->where($query->qualifyColumn('author_id'), $user->id);
            }
        };
        $this->registerPolicy($gate, $policy, QueryAwareSoftDeletingPost::class);

        $selection = $gate->select('edit', $this->createQueryBuilder(new QueryAwareSoftDeletingPost));

        $this->assertTrue($policy->extensionCalled);
        $this->assertStringNotContainsString('deleted_at', $selection->toSql());
    }

    public function testDerivedScopeRequiresNonEmptyPrimaryKey(): void
    {
        $gate = $this->getGate();
        $gate->policy(QueryAwareEmptyKeyPost::class, ScopeOnlyPostPolicy::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not define a primary key');

        $gate->select('edit', $this->createQueryBuilder(new QueryAwareEmptyKeyPost));
    }

    public function testSelectAcceptsModelClassAndInstanceShorthand(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $classSelection = $gate->select('edit', ScopablePost::class);
        $instanceSelection = $gate->select('edit', new ScopablePost);

        $this->assertInstanceOf(QueryBuilder::class, $classSelection);
        $this->assertInstanceOf(QueryBuilder::class, $instanceSelection);
        $this->assertSame([1], $classSelection->getBindings());
        $this->assertSame([1], $instanceSelection->getBindings());
    }

    public function testScopeResolvesCurrentUserForEachOperation(): void
    {
        $gate = $this->gateForUser((object) [
            'id' => 42,
            'is_admin' => false,
        ]);
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $query = $gate->scope('edit', $this->createQueryBuilder());

        $this->assertSame([42], $query->getBindings());
    }

    public function testForUserOverridesCurrentUser(): void
    {
        $gate = $this->getGate();
        $gate->policy(ScopablePost::class, ScopablePostPolicy::class);

        $query = $gate
            ->forUser((object) ['id' => 99, 'is_admin' => false])
            ->scope('edit', $this->createQueryBuilder());

        $this->assertSame([99], $query->getBindings());
    }

    public function testGateBeforeAllowsOrDeniesScopeWithoutPolicyLookup(): void
    {
        $allowingGate = $this->getGate();
        $allowingGate->before(fn () => true);
        $denyingGate = $this->getGate();
        $denyingGate->before(fn () => false);
        $allowedQuery = $this->createQueryBuilder();
        $deniedQuery = $this->createQueryBuilder()
            ->where('tenant_id', 10)
            ->orWhere('is_public', true);

        $allowingGate->scope('missing', $allowedQuery);
        $denyingGate->scope('missing', $deniedQuery);

        $this->assertSame('select * from "posts"', $allowedQuery->toSql());
        $this->assertSame(
            'select * from "posts" where ("tenant_id" = ? or "is_public" = ?) and (0 = 1)',
            $deniedQuery->toSql(),
        );
        $this->assertSame([10, true], $deniedQuery->getBindings());
    }

    public function testPolicyBeforeAllowsOrFallsThroughOnce(): void
    {
        $adminGate = $this->getGate(isAdmin: true);
        $adminGate->policy(ScopablePost::class, ScopablePostPolicyWithBefore::class);
        $userGate = $this->getGate();
        $userGate->policy(ScopablePost::class, ScopablePostPolicyWithBefore::class);
        $adminQuery = $this->createQueryBuilder();
        $userQuery = $this->createQueryBuilder();

        $adminGate->scope('edit', $adminQuery);
        $adminSelection = $adminGate->select('edit', $this->createQueryBuilder());
        $userGate->scope('edit', $userQuery);

        $this->assertSame('select * from "posts"', $adminQuery->toSql());
        $this->assertSame(
            'true',
            $this->expressionValue($adminSelection, $adminQuery->getQuery()->getGrammar()),
        );
        $this->assertSame([1], $userQuery->getBindings());
    }
}

enum QueryAwarePolicyAbility
{
    case Edit;
}

class QueryAwareSoftDeletingPost extends Model
{
    use SoftDeletes;

    protected ?string $table = 'posts';
}

class QueryAwareEmptyKeyPost extends ScopablePost
{
    protected string $primaryKey = '';
}
