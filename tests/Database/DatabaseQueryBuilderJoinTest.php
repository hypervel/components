<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseQueryBuilderJoinTest extends TestCase
{
    public function testNestedJoinSupportsGroupedOnConditions(): void
    {
        $query = $this->builder()->from('users')
            ->join('contacts', function (JoinClause $join): void {
                $join->on('users.id', '=', 'contacts.user_id')
                    ->where('contacts.active', true)
                    ->join('addresses', function (JoinClause $nested): void {
                        $nested->on(function (JoinClause $conditions): void {
                            $conditions->on('contacts.id', '=', 'addresses.contact_id')
                                ->where('addresses.kind', 'home')
                                ->orWhere('addresses.kind', 'work');
                        });
                    });
            })
            ->where('users.tenant_id', 7);

        $this->assertSame(
            'select * from "users" inner join ("contacts" inner join "addresses" on ("contacts"."id" = "addresses"."contact_id" and "addresses"."kind" = ? or "addresses"."kind" = ?)) on "users"."id" = "contacts"."user_id" and "contacts"."active" = ? where "users"."tenant_id" = ?',
            $query->toSql()
        );
        $this->assertSame(['home', 'work', true, 7], $query->getBindings());
    }

    public function testNestedJoinSupportsClosureSubqueries(): void
    {
        $query = $this->builder()->from('users')
            ->join('contacts', function (JoinClause $join): void {
                $join->on('users.id', '=', 'contacts.user_id')
                    ->join('addresses', function (JoinClause $nested): void {
                        $nested->on('contacts.id', '=', 'addresses.contact_id')
                            ->whereExists(function (Builder $query): void {
                                $this->assertSame(Builder::class, $query::class);

                                $query->selectRaw('1')->from('countries')
                                    ->whereColumn('countries.id', '=', 'addresses.country_id')
                                    ->where('countries.active', true);
                            });
                    });
            });

        $this->assertSame(
            'select * from "users" inner join ("contacts" inner join "addresses" on "contacts"."id" = "addresses"."contact_id" and exists (select 1 from "countries" where "countries"."id" = "addresses"."country_id" and "countries"."active" = ?)) on "users"."id" = "contacts"."user_id"',
            $query->toSql()
        );
        $this->assertSame([true], $query->getBindings());
    }

    public function testNestedJoinFactoriesRetainTheRootBuilderSubclassAndDependencies(): void
    {
        $builder = $this->builder();
        $root = new class($builder->getConnection(), $builder->getGrammar(), $builder->getProcessor()) extends Builder {};
        $root->from('users')->join('contacts', function (JoinClause $join) use ($root): void {
            $join->on('users.id', '=', 'contacts.user_id')
                ->join('addresses', function (JoinClause $nested) use ($root): void {
                    $nested->on(function (JoinClause $conditions) use ($root): void {
                        $this->assertSame($root->getConnection(), $conditions->getConnection());
                        $this->assertSame($root->getGrammar(), $conditions->getGrammar());
                        $this->assertSame($root->getProcessor(), $conditions->getProcessor());

                        $conditions->on('contacts.id', '=', 'addresses.contact_id')
                            ->where('addresses.country_id', '=', function (Builder $query) use ($root): void {
                                $this->assertSame($root::class, $query::class);
                                $this->assertNotSame($root, $query);
                                $this->assertSame($root->getConnection(), $query->getConnection());
                                $this->assertSame($root->getGrammar(), $query->getGrammar());
                                $this->assertSame($root->getProcessor(), $query->getProcessor());

                                $query->select('id')->from('countries')->where('code', 'GB');
                            });
                    });
                });
        });

        $this->assertSame(
            'select * from "users" inner join ("contacts" inner join "addresses" on ("contacts"."id" = "addresses"."contact_id" and "addresses"."country_id" = (select "id" from "countries" where "code" = ?))) on "users"."id" = "contacts"."user_id"',
            $root->toSql()
        );
        $this->assertSame(['GB'], $root->getBindings());
    }

    #[DataProvider('joinWhereOperands')]
    public function testJoinWhereHelpersAcceptValueOperands(string $method, string $join, mixed $value, string $predicate, array $bindings): void
    {
        $query = $this->builder();
        $query->grammar = new MySqlGrammar($query->getConnection());
        $query->from('users')->{$method}('contacts', 'contacts.active', '=', $value);

        $this->assertSame('select * from `users` ' . $join . ' `contacts` on `contacts`.`active` ' . $predicate, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
    }

    /**
     * Cover each value-comparison helper and its binding or inline operand path.
     */
    public static function joinWhereOperands(): iterable
    {
        foreach ([
            'joinWhere' => 'inner join',
            'leftJoinWhere' => 'left join',
            'rightJoinWhere' => 'right join',
            'straightJoinWhere' => 'straight_join',
        ] as $method => $join) {
            yield $method . ' boolean' => [$method, $join, true, '= ?', [true]];
            yield $method . ' integer' => [$method, $join, 1, '= ?', [1]];
            yield $method . ' null' => [$method, $join, null, 'is null', []];
            yield $method . ' expression' => [$method, $join, new Expression('1'), '= 1', []];
        }
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
