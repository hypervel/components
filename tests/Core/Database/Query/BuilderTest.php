<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Query;

use Closure;
use Hyperf\Database\Query\Expression;
use Hypervel\Database\Query\Builder;

/**
 * Unit tests for custom Query Builder methods.
 *
 * These tests verify that Builder methods correctly populate the query state
 * (wheres, havings, bindings, etc.) without executing against a real database.
 *
 * @internal
 * @coversNothing
 */
class BuilderTest extends QueryTestCase
{
    // =========================================================================
    // whereNot tests
    // =========================================================================

    public function testWhereNotAddsWhereClauseWithNotBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->whereNot('name', 'John');

        $this->assertCount(1, $builder->wheres);
        $this->assertSame('Basic', $builder->wheres[0]['type']);
        $this->assertSame('name', $builder->wheres[0]['column']);
        $this->assertSame('and not', $builder->wheres[0]['boolean']);
        $this->assertEquals(['John'], $builder->getBindings());
    }

    public function testWhereNotWithOperator(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->whereNot('age', '>', 18);

        $this->assertSame('>', $builder->wheres[0]['operator']);
        $this->assertSame('and not', $builder->wheres[0]['boolean']);
        $this->assertEquals([18], $builder->getBindings());
    }

    public function testWhereNotWithArray(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->whereNot([
            ['name', 'John'],
            ['age', '>', 18],
        ]);

        $this->assertCount(1, $builder->wheres);
        $this->assertSame('Nested', $builder->wheres[0]['type']);
        $this->assertSame('and not', $builder->wheres[0]['boolean']);
    }

    public function testOrWhereNotUsesOrNotBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereNot('name', 'John');

        $this->assertCount(2, $builder->wheres);
        $this->assertSame('or not', $builder->wheres[1]['boolean']);
    }

    // =========================================================================
    // whereLike tests
    // =========================================================================

    public function testWhereLikeAddsCorrectWhereClause(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->whereLike('name', 'John%');

        $this->assertCount(1, $builder->wheres);
        $this->assertSame('Like', $builder->wheres[0]['type']);
        $this->assertSame('name', $builder->wheres[0]['column']);
        $this->assertSame('John%', $builder->wheres[0]['value']);
        $this->assertFalse($builder->wheres[0]['caseSensitive']);
        $this->assertSame('and', $builder->wheres[0]['boolean']);
        $this->assertFalse($builder->wheres[0]['not']);
    }

    public function testWhereLikeWithCaseSensitive(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->whereLike('name', 'John%', true);

        $this->assertTrue($builder->wheres[0]['caseSensitive']);
    }

    public function testOrWhereLikeUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereLike('name', 'John%');

        $this->assertSame('or', $builder->wheres[1]['boolean']);
        $this->assertFalse($builder->wheres[1]['not']);
    }

    public function testWhereNotLikeSetsNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->whereNotLike('name', 'John%');

        $this->assertTrue($builder->wheres[0]['not']);
        $this->assertSame('and', $builder->wheres[0]['boolean']);
    }

    public function testOrWhereNotLikeUsesOrBooleanAndNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereNotLike('name', 'John%');

        $this->assertSame('or', $builder->wheres[1]['boolean']);
        $this->assertTrue($builder->wheres[1]['not']);
    }

    // =========================================================================
    // orWhereIntegerInRaw / orWhereIntegerNotInRaw tests
    // =========================================================================

    public function testOrWhereIntegerInRawUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereIntegerInRaw('id', [1, 2, 3]);

        $this->assertCount(2, $builder->wheres);
        $this->assertSame('InRaw', $builder->wheres[1]['type']);
        $this->assertSame('or', $builder->wheres[1]['boolean']);
    }

    public function testOrWhereIntegerNotInRawUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereIntegerNotInRaw('id', [1, 2, 3]);

        $this->assertCount(2, $builder->wheres);
        $this->assertSame('NotInRaw', $builder->wheres[1]['type']);
        $this->assertSame('or', $builder->wheres[1]['boolean']);
    }

    // =========================================================================
    // whereBetweenColumns tests
    // =========================================================================

    public function testWhereBetweenColumnsAddsCorrectClause(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->whereBetweenColumns('created_at', ['start_date', 'end_date']);

        $this->assertCount(1, $builder->wheres);
        $this->assertSame('betweenColumns', $builder->wheres[0]['type']);
        $this->assertSame('created_at', $builder->wheres[0]['column']);
        $this->assertEquals(['start_date', 'end_date'], $builder->wheres[0]['values']);
        $this->assertSame('and', $builder->wheres[0]['boolean']);
        $this->assertFalse($builder->wheres[0]['not']);
    }

    public function testOrWhereBetweenColumnsUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereBetweenColumns('created_at', ['start_date', 'end_date']);

        $this->assertSame('or', $builder->wheres[1]['boolean']);
        $this->assertFalse($builder->wheres[1]['not']);
    }

    public function testWhereNotBetweenColumnsSetsNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->whereNotBetweenColumns('created_at', ['start_date', 'end_date']);

        $this->assertTrue($builder->wheres[0]['not']);
        $this->assertSame('and', $builder->wheres[0]['boolean']);
    }

    public function testOrWhereNotBetweenColumnsUsesOrBooleanAndNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereNotBetweenColumns('created_at', ['start_date', 'end_date']);

        $this->assertSame('or', $builder->wheres[1]['boolean']);
        $this->assertTrue($builder->wheres[1]['not']);
    }

    // =========================================================================
    // whereJsonContainsKey tests
    // =========================================================================

    public function testWhereJsonContainsKeyAddsCorrectClause(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->whereJsonContainsKey('options->notifications');

        $this->assertCount(1, $builder->wheres);
        $this->assertSame('JsonContainsKey', $builder->wheres[0]['type']);
        $this->assertSame('options->notifications', $builder->wheres[0]['column']);
        $this->assertSame('and', $builder->wheres[0]['boolean']);
        $this->assertFalse($builder->wheres[0]['not']);
    }

    public function testOrWhereJsonContainsKeyUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereJsonContainsKey('options->notifications');

        $this->assertSame('or', $builder->wheres[1]['boolean']);
    }

    public function testWhereJsonDoesntContainKeySetsNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->whereJsonDoesntContainKey('options->notifications');

        $this->assertTrue($builder->wheres[0]['not']);
    }

    public function testOrWhereJsonDoesntContainKeyUsesOrBooleanAndNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->where('active', true)
            ->orWhereJsonDoesntContainKey('options->notifications');

        $this->assertSame('or', $builder->wheres[1]['boolean']);
        $this->assertTrue($builder->wheres[1]['not']);
    }

    // =========================================================================
    // havingNull tests
    // =========================================================================

    public function testHavingNullAddsCorrectClause(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingNull('manager_id');

        $this->assertCount(1, $builder->havings);
        $this->assertSame('Null', $builder->havings[0]['type']);
        $this->assertSame('manager_id', $builder->havings[0]['column']);
        $this->assertSame('and', $builder->havings[0]['boolean']);
    }

    public function testHavingNullWithArrayOfColumns(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingNull(['manager_id', 'supervisor_id']);

        $this->assertCount(2, $builder->havings);
        $this->assertSame('manager_id', $builder->havings[0]['column']);
        $this->assertSame('supervisor_id', $builder->havings[1]['column']);
    }

    public function testOrHavingNullUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingRaw('count(*) > 5')
            ->orHavingNull('manager_id');

        $this->assertSame('or', $builder->havings[1]['boolean']);
    }

    public function testHavingNotNullAddsNotNullType(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingNotNull('manager_id');

        $this->assertSame('NotNull', $builder->havings[0]['type']);
    }

    public function testOrHavingNotNullUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingRaw('count(*) > 5')
            ->orHavingNotNull('manager_id');

        $this->assertSame('NotNull', $builder->havings[1]['type']);
        $this->assertSame('or', $builder->havings[1]['boolean']);
    }

    // =========================================================================
    // havingBetween variants tests
    // =========================================================================

    public function testOrHavingBetweenUsesOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingRaw('count(*) > 5')
            ->orHavingBetween('total', [10, 100]);

        $this->assertSame('or', $builder->havings[1]['boolean']);
        $this->assertFalse($builder->havings[1]['not']);
    }

    public function testHavingNotBetweenSetsNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingNotBetween('total', [10, 100]);

        $this->assertTrue($builder->havings[0]['not']);
    }

    public function testOrHavingNotBetweenUsesOrBooleanAndNotFlag(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingRaw('count(*) > 5')
            ->orHavingNotBetween('total', [10, 100]);

        $this->assertSame('or', $builder->havings[1]['boolean']);
        $this->assertTrue($builder->havings[1]['not']);
    }

    // =========================================================================
    // havingNested tests
    // =========================================================================

    public function testHavingNestedAddsNestedClause(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingNested(function (Builder $query) {
                $query->havingRaw('count(*) > 5')
                    ->havingRaw('sum(salary) > 10000');
            });

        $this->assertCount(1, $builder->havings);
        $this->assertSame('Nested', $builder->havings[0]['type']);
        $this->assertSame('and', $builder->havings[0]['boolean']);
    }

    public function testHavingNestedWithOrBoolean(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->groupBy('department')
            ->havingRaw('avg(age) > 30')
            ->havingNested(function (Builder $query) {
                $query->havingRaw('count(*) > 5');
            }, 'or');

        $this->assertSame('or', $builder->havings[1]['boolean']);
    }

    // =========================================================================
    // beforeQuery / afterQuery tests
    // =========================================================================

    public function testBeforeQueryRegistersCallback(): void
    {
        $builder = $this->getBuilder();
        $builder->beforeQuery(function ($query) {
            $query->where('active', true);
        });

        $this->assertCount(1, $builder->beforeQueryCallbacks);
    }

    public function testApplyBeforeQueryCallbacksInvokesAndClearsCallbacks(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');
        $builder->beforeQuery(function ($query) {
            $query->where('active', true);
        });

        $this->assertCount(0, $builder->wheres);

        $builder->applyBeforeQueryCallbacks();

        $this->assertCount(1, $builder->wheres);
        $this->assertCount(0, $builder->beforeQueryCallbacks);
    }

    public function testToSqlInvokesBeforeQueryCallbacks(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');
        $builder->beforeQuery(function ($query) {
            $query->where('active', true);
        });

        $sql = $builder->toSql();

        $this->assertStringContainsString('where', $sql);
        $this->assertStringContainsString('active', $sql);
    }

    public function testAfterQueryRegistersCallback(): void
    {
        $builder = $this->getBuilder();
        $builder->afterQuery(function ($results) {
            return array_merge($results, ['added']);
        });

        // Verify callback was registered by checking it transforms results
        $result = $builder->applyAfterQueryCallbacks([]);
        $this->assertEquals(['added'], $result);
    }

    public function testApplyAfterQueryCallbacksTransformsResult(): void
    {
        $builder = $this->getBuilder();
        $builder->afterQuery(function ($results) {
            return array_map(fn ($r) => (object) ['transformed' => true], $results);
        });

        $result = $builder->applyAfterQueryCallbacks([['id' => 1]]);

        $this->assertTrue($result[0]->transformed);
    }

    public function testChainedAfterQueryCallbacks(): void
    {
        $builder = $this->getBuilder();
        $builder->afterQuery(function ($results) {
            return array_merge($results, ['first']);
        });
        $builder->afterQuery(function ($results) {
            return array_merge($results, ['second']);
        });

        $result = $builder->applyAfterQueryCallbacks([]);

        $this->assertEquals(['first', 'second'], $result);
    }

    // =========================================================================
    // getLimit / getOffset tests
    // =========================================================================

    public function testGetLimitReturnsNullWhenNotSet(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');

        $this->assertNull($builder->getLimit());
    }

    public function testGetLimitReturnsValue(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->limit(10);

        $this->assertSame(10, $builder->getLimit());
    }

    public function testGetLimitReturnsUnionLimitForUnionQueries(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->limit(10);

        $builder2 = $this->getBuilder();
        $builder2->select('*')->from('admins');

        $builder->union($builder2)->limit(5);

        $this->assertSame(5, $builder->getLimit());
    }

    public function testGetOffsetReturnsNullWhenNotSet(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');

        $this->assertNull($builder->getOffset());
    }

    public function testGetOffsetReturnsValue(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->offset(20);

        $this->assertSame(20, $builder->getOffset());
    }

    public function testGetOffsetReturnsUnionOffsetForUnionQueries(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->offset(10);

        $builder2 = $this->getBuilder();
        $builder2->select('*')->from('admins');

        $builder->union($builder2)->offset(15);

        $this->assertSame(15, $builder->getOffset());
    }

    // =========================================================================
    // groupByRaw tests
    // =========================================================================

    public function testGroupByRawAddsExpression(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->groupByRaw('DATE(created_at)');

        $this->assertCount(1, $builder->groups);
        $this->assertInstanceOf(Expression::class, $builder->groups[0]);
        $this->assertSame('DATE(created_at)', (string) $builder->groups[0]);
    }

    public function testGroupByRawWithBindings(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->groupByRaw('YEAR(created_at) + ?', [2000]);

        $this->assertEquals([2000], $builder->getRawBindings()['groupBy']);
    }

    // =========================================================================
    // groupLimit tests
    // =========================================================================

    public function testGroupLimitSetsProperty(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->groupLimit(3, 'department_id');

        $this->assertNotNull($builder->groupLimit);
        $this->assertSame(3, $builder->groupLimit['value']);
        $this->assertSame('department_id', $builder->groupLimit['column']);
    }

    public function testGroupLimitIgnoresNegativeValues(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->groupLimit(-1, 'department_id');

        $this->assertNull($builder->groupLimit);
    }

    public function testGroupLimitAllowsZero(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->groupLimit(0, 'department_id');

        $this->assertNotNull($builder->groupLimit);
        $this->assertSame(0, $builder->groupLimit['value']);
    }

    // =========================================================================
    // reorderDesc tests
    // =========================================================================

    public function testReorderDescClearsOrdersAndAddsDescending(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')
            ->orderBy('name', 'asc')
            ->reorderDesc('created_at');

        $this->assertCount(1, $builder->orders);
        $this->assertSame('created_at', $builder->orders[0]['column']);
        $this->assertSame('desc', $builder->orders[0]['direction']);
    }

    // =========================================================================
    // crossJoinSub tests
    // =========================================================================

    public function testCrossJoinSubAddsJoin(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');

        $subquery = $this->getBuilder();
        $subquery->select('*')->from('departments');

        $builder->crossJoinSub($subquery, 'depts');

        $this->assertCount(1, $builder->joins);
        $this->assertSame('cross', $builder->joins[0]->type);
    }

    public function testCrossJoinSubWithClosure(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users');

        $builder->crossJoinSub(function ($query) {
            $query->select('id', 'name')->from('departments');
        }, 'depts');

        $this->assertCount(1, $builder->joins);
        $this->assertSame('cross', $builder->joins[0]->type);
    }
}
