<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseQueryBuilderEmbeddingTest extends TestCase
{
    #[DataProvider('attachmentPaths')]
    public function testEmbeddingValidationCanRejectBeforeChangingTheOuterQuery(Closure $attach): void
    {
        $outer = $this->builder()->from('users')->where('active', true);
        $child = $this->builder()->select('id')->from('memberships')->where('enabled', true);
        $failure = new InvalidArgumentException('Apply statement options to the outer query.');
        $outer->embeddingFailure = $failure;
        $sql = $outer->toSql();
        $bindings = $outer->getBindings();

        try {
            $attach($outer, $child);
            $this->fail('The attachment must invoke the embedding validation override.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([$child], $outer->validatedQueries);
        $this->assertSame($sql, $outer->toSql());
        $this->assertSame($bindings, $outer->getBindings());
        $this->assertSame('select "id" from "memberships" where "enabled" = ?', $child->toSql());
        $this->assertSame([true], $child->getBindings());
    }

    #[DataProvider('attachmentPaths')]
    public function testEmbeddingValidationRunsOnlyWhenTheChildIsAttached(Closure $attach): void
    {
        $outer = $this->builder()->from('users');
        $child = $this->builder()->select('id')->from('memberships')->where('enabled', true);
        $attach($outer, $child);
        $sql = $outer->toSql();
        $bindings = $outer->getBindings();

        $outer->embeddingFailure = new InvalidArgumentException('This must not run during compilation.');
        $child->timeout(5);

        $this->assertSame($sql, $outer->toSql());
        $this->assertSame($bindings, $outer->getBindings());
        $this->assertSame([$child], $outer->validatedQueries);
        $this->assertSame(5, $child->timeout);
    }

    /**
     * Exercise each distinct attachment-time validation site.
     */
    public static function attachmentPaths(): iterable
    {
        yield 'parsed subquery' => [static fn (Builder $outer, Builder $child): Builder => $outer->selectSub($child, 'member_id')];
        yield 'scalar subquery' => [static fn (Builder $outer, Builder $child): Builder => $outer->where('id', '=', $child)];
        yield 'exists subquery' => [static fn (Builder $outer, Builder $child): Builder => $outer->whereExists($child)];
        yield 'union member' => [static fn (Builder $outer, Builder $child): Builder => $outer->union($child)];
    }

    /**
     * Construct a builder without opening a database connection.
     */
    protected function builder(): EmbeddingAwareQueryBuilder
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('getDatabaseName')->andReturn('database');

        return new EmbeddingAwareQueryBuilder($connection, new Grammar($connection), new Processor);
    }
}

class EmbeddingAwareQueryBuilder extends Builder
{
    /**
     * @var list<Builder>
     */
    public array $validatedQueries = [];

    public ?InvalidArgumentException $embeddingFailure = null;

    /**
     * Extend embedding validation while retaining the framework timeout rule.
     */
    protected function ensureCanEmbedQuery(Builder $query): void
    {
        parent::ensureCanEmbedQuery($query);

        $this->validatedQueries[] = $query;

        if ($this->embeddingFailure !== null) {
            throw $this->embeddingFailure;
        }
    }
}
