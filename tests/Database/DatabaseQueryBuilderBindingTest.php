<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use DateTimeImmutable;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class DatabaseQueryBuilderBindingTest extends TestCase
{
    public function testHavingAcceptsBooleanOperandsInBothOverloadsAndNestedClauses(): void
    {
        $query = $this->builder()->from('users')
            ->having('active', true)
            ->orHaving('verified', '=', false)
            ->having(function (Builder $query): void {
                $query->having('enabled', '=', true)->orHaving('invited', false);
            });

        $this->assertSame(
            'select * from "users" having "active" = ? or "verified" = ? and ("enabled" = ? or "invited" = ?)',
            $query->toSql()
        );
        $this->assertSame([true, false, true, false], $query->getBindings());
    }

    public function testHavingRetainsTheFrameworkArrayCoercion(): void
    {
        $query = $this->builder()->from('users')
            ->having('id', [2, 3])
            ->orHaving('id', '=', [[4, 5]]);

        $this->assertSame('select * from "users" having "id" = ? or "id" = ?', $query->toSql());
        $this->assertSame([2, 4], $query->getBindings());
    }

    public function testHavingPreservesDriverOwnedValueObjects(): void
    {
        $value = new stdClass;
        $query = $this->builder()->from('users')->having('id', $value)->orHaving('id', '=', $value);

        $this->assertSame('select * from "users" having "id" = ? or "id" = ?', $query->toSql());
        $this->assertSame([$value, $value], $query->getBindings());
    }

    public function testHavingExpressionShorthandDoesNotAddBindings(): void
    {
        $query = $this->builder()->from('users')
            ->having('id', new Expression('2'))
            ->orHaving('id', new Expression('3'));

        $this->assertSame('select * from "users" having "id" = 2 or "id" = 3', $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    #[DataProvider('valueBetweenMethods')]
    public function testValueBetweenExpressionsDoNotAddBindings(string $method, string $boolean, string $operator): void
    {
        $query = $this->builder()->from('users')->where('active', true);
        $query->{$method}(new Expression('2'), ['lower', 'upper']);

        $this->assertSame(
            'select * from "users" where "active" = ? ' . $boolean . ' 2 ' . $operator . ' "lower" and "upper"',
            $query->toSql()
        );
        $this->assertSame([true], $query->getBindings());
    }

    #[DataProvider('valueBetweenMethods')]
    public function testValueBetweenArraysContributeOneScalarBinding(string $method, string $boolean, string $operator): void
    {
        $query = $this->builder()->from('users')->where('active', true);
        $query->{$method}([[2, 3]], ['lower', 'upper']);

        $this->assertSame(
            'select * from "users" where "active" = ? ' . $boolean . ' ? ' . $operator . ' "lower" and "upper"',
            $query->toSql()
        );
        $this->assertSame([true, 2], $query->getBindings());
    }

    /**
     * Cover the Boolean and negated value-between entry points.
     */
    public static function valueBetweenMethods(): iterable
    {
        yield 'where' => ['whereValueBetween', 'and', 'between'];
        yield 'or where' => ['orWhereValueBetween', 'or', 'between'];
        yield 'where not' => ['whereValueNotBetween', 'and', 'not between'];
        yield 'or where not' => ['orWhereValueNotBetween', 'or', 'not between'];
    }

    public function testBooleanHavingAndInlineValueBetweenExecuteWithoutExtraBindings(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $query = $connection->query()->selectRaw('1 AS active')
            ->whereValueBetween(new Expression('2'), [new Expression('1'), new Expression('3')])
            ->groupBy('active')->having('active', true);

        $this->assertSame([true], $query->getBindings());
        $this->assertSame(1, $query->get()->sole()->active);
    }

    #[DataProvider('datePartMethods')]
    public function testDatePartsPreserveDriverOwnedValueObjects(string $method, string $part): void
    {
        $value = new stdClass;
        $query = $this->builder()->from('users')->{$method}('created_at', $value);

        $this->assertSame('select * from "users" where ' . $part . '("created_at") = ?', $query->toSql());
        $this->assertSame([$value], $query->getBindings());
    }

    #[DataProvider('datePartMethods')]
    public function testDatePartsKeepScalarDateTimeAndExpressionBehavior(string $method, string $part): void
    {
        $query = $this->builder()->from('users')
            ->{$method}('created_at', 5)
            ->{$method}('created_at', '=', new DateTimeImmutable('2026-05-05'))
            ->{$method}('created_at', '=', new Expression('5'));

        $predicate = $part . '("created_at")';
        $this->assertSame(
            'select * from "users" where ' . $predicate . ' = ? and ' . $predicate . ' = ? and ' . $predicate . ' = 5',
            $query->toSql()
        );
        $this->assertSame(['05', '05'], $query->getBindings());
    }

    /**
     * Cover the two date-part formatting boundaries.
     */
    public static function datePartMethods(): iterable
    {
        yield 'day' => ['whereDay', 'day'];
        yield 'month' => ['whereMonth', 'month'];
    }

    #[DataProvider('betweenMethods')]
    public function testBetweenMaterializesGeneratorBoundsOnce(string $method, string $clause): void
    {
        $iterations = 0;
        $bounds = (static function () use (&$iterations) {
            ++$iterations;

            yield 'lower' => new Expression('1');
            yield 'upper' => 3;
        })();
        $query = $this->builder()->from('users')->{$method}('id', $bounds);
        $sql = 'select * from "users" ' . $clause . ' "id" between 1 and ?';

        $this->assertSame(1, $iterations);
        $this->assertSame($sql, $query->toSql());
        $this->assertSame($sql, $query->toSql());
        $this->assertSame([3], $query->getBindings());
        $this->assertSame(1, $iterations);
    }

    #[DataProvider('betweenMethods')]
    public function testBetweenAcceptsCollectionBounds(string $method, string $clause): void
    {
        $query = $this->builder()->from('users')
            ->{$method}('id', new Collection(['lower' => 1, 'upper' => 3]));

        $this->assertSame('select * from "users" ' . $clause . ' "id" between ? and ?', $query->toSql());
        $this->assertSame([1, 3], $query->getBindings());
    }

    /**
     * Cover the distinct where and having bound collectors.
     */
    public static function betweenMethods(): iterable
    {
        yield 'where' => ['whereBetween', 'where'];
        yield 'having' => ['havingBetween', 'having'];
    }

    /**
     * Construct a builder without opening a database connection.
     */
    protected function builder(): Builder
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');

        return new Builder($connection, new Grammar($connection), new Processor);
    }
}
