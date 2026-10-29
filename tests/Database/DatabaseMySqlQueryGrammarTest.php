<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseMySqlQueryGrammarTest extends TestCase
{
    #[DataProvider('jsonPathEscapingProvider')]
    public function testJsonPathsEscapeKeysUsingTheConfiguredSqlMode(array $modes, string $path): void
    {
        $connection = new MySqlConnection(m::mock(PDO::class), config: ['modes' => $modes]);
        $builder = $connection->table('users')->select('options->App\Models\User->a"b[0]');

        $this->assertSame("select json_unquote(json_extract(`options`, {$path})) from `users`", $builder->toSql());
        $this->assertSame(
            "update `users` set `options` = json_set(`options`, {$path}, ?)",
            $builder->getGrammar()->compileUpdate($builder, ['options->App\Models\User->a"b[0]' => 'John']),
        );
    }

    /**
     * Provide SQL modes and their JSON path literals.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function jsonPathEscapingProvider(): array
    {
        return [
            'backslash escapes' => [[], <<<'SQL'
'$."App\\\\Models\\\\User"."a\\"b"[0]'
SQL],
            'literal backslashes' => [['STRICT_TRANS_TABLES,no_backslash_escapes'], <<<'SQL'
'$."App\\Models\\User"."a\"b"[0]'
SQL],
        ];
    }

    public function testUpdateBindingsRejectUnencodableArrays(): void
    {
        $this->expectException(JsonException::class);

        (new MySqlGrammar(m::mock(Connection::class)))
            ->prepareBindingsForUpdate([], ['payload' => [NAN]]);
    }

    public function testToRawSql(): void
    {
        $connection = m::mock(Connection::class);
        $connection->expects('escape')->with('foo', false)->andReturn("'foo'");
        $grammar = new MySqlGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = \'foo\'', $query);
    }

    public function testTimeout(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->where('email', 'like', '%test%')->timeout(60);

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(60000) */ * from `users` where `email` like ?',
            $builder->toSql()
        );
    }

    public function testTimeoutWithDistinct(): void
    {
        $builder = $this->getBuilder();
        $builder->distinct()->select('*')->from('users')->timeout(30);
        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(30000) */ distinct * from `users`',
            $builder->toSql()
        );
    }

    public function testTimeoutWithAggregate(): void
    {
        $builder = $this->getBuilder();
        $builder->from('users')->timeout(10);
        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];
        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(10000) */ count(*) as `aggregate` from `users`',
            $builder->toSql()
        );
    }

    public function testTimeoutDecoratesOrdinaryAndAggregateUnionStatementsOnce(): void
    {
        $builder = $this->getBuilder()
            ->select('*')
            ->from('posts')
            ->where('published', true)
            ->unionAll(
                $this->getBuilder()
                    ->select('*')
                    ->from('videos')
                    ->where('published', false)
            )
            ->timeout(5);

        $this->assertSame(
            '(select /*+ MAX_EXECUTION_TIME(5000) */ * from `posts` where `published` = ?) union all (select * from `videos` where `published` = ?)',
            $builder->toSql()
        );
        $this->assertSame([true, false], $builder->getBindings());

        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        $sql = $builder->toSql();

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(5000) */ count(*) as `aggregate` from ((select * from `posts` where `published` = ?) union all (select * from `videos` where `published` = ?)) as `temp_table`',
            $sql
        );
        $this->assertSame(1, substr_count($sql, 'MAX_EXECUTION_TIME'));
    }

    public function testTimeoutDecoratesExistsAndLockingStatementsAtTheRoot(): void
    {
        $exists = $this->getBuilder()->from('users')->where('active', true)->timeout(4);

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(4000) */ exists(select * from `users` where `active` = ?) as `exists`',
            $exists->getGrammar()->compileExists($exists)
        );

        $locking = $this->getBuilder()->from('users')->where('id', 1)->lockForUpdate()->timeout(3);

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(3000) */ * from `users` where `id` = ? for update',
            $locking->toSql()
        );
    }

    #[DataProvider('nestedUnionVersions')]
    public function testNestedUnionMembersUseTheSupportedServerSyntax(string $connectionClass, string $version, bool $wrapped): void
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn($version);
        $connection = new $connectionClass($pdo, 'database');
        $member = $connection->table('posts')->select('id')->where('id', '<=', 3)
            ->unionAll($connection->table('posts')->select('id')->whereBetween('id', [4, 6]))
            ->orderByDesc('id')->offset(1)->limit(3);
        $query = $connection->table('posts')->select('id')->where('id', 7)
            ->unionAll($member)->orderByDesc('id')->limit(2);
        $memberSql = '(select `id` from `posts` where `id` <= ?) union all (select `id` from `posts` where `id` between ? and ?) order by `id` desc limit 3 offset 1';

        $this->assertSame(
            '(select `id` from `posts` where `id` = ?) union all ('
                . ($wrapped ? 'select * from (' . $memberSql . ') as `union_table`' : $memberSql)
                . ') order by `id` desc limit 2',
            $query->toSql()
        );
        $this->assertSame([7, 3, 4, 6], $query->getBindings());
        $this->assertSame(3, $member->unionLimit);
        $this->assertSame(1, $member->unionOffset);
    }

    /**
     * Cover native nesting and older syntax through both MySQL-compatible drivers.
     */
    public static function nestedUnionVersions(): iterable
    {
        yield 'MySQL 5.7' => [MySqlConnection::class, '5.7.44', true];
        yield 'MySQL 8.0.30' => [MySqlConnection::class, '8.0.30', true];
        yield 'MySQL 8.0.31' => [MySqlConnection::class, '8.0.31', false];
        yield 'MariaDB 10.11 through mysql' => [MySqlConnection::class, '10.11.19-MariaDB', false];
        yield 'MariaDB 10.11' => [MariaDbConnection::class, '10.11.19-MariaDB', false];
    }

    public function testOrdinaryUnionMembersDoNotInspectTheServerVersion(): void
    {
        $connection = m::mock(MySqlConnection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldNotReceive('getServerVersion');
        $connection->shouldNotReceive('isMaria');
        $query = new Builder($connection, new MySqlGrammar($connection), new Processor);
        $query->from('posts')->select('id')->unionAll($query->newQuery()->from('archives')->select('id'));

        $this->assertSame('(select `id` from `posts`) union all (select `id` from `archives`)', $query->toSql());
    }

    public function testOuterTimeoutLeavesAnUntimedSubqueryUndecorated(): void
    {
        $subquery = $this->getBuilder()->select('user_id')->from('memberships')->where('active', true);
        $builder = $this->getBuilder()
            ->select('*')
            ->from('users')
            ->whereIn('id', $subquery)
            ->where('status', 'enabled')
            ->timeout(6);

        $sql = $builder->toSql();

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(6000) */ * from `users` where `id` in (select `user_id` from `memberships` where `active` = ?) and `status` = ?',
            $sql
        );
        $this->assertSame([true, 'enabled'], $builder->getBindings());
        $this->assertSame(1, substr_count($sql, 'MAX_EXECUTION_TIME'));
    }

    public function testTimeoutNullRemovesTimeout(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->timeout(60)->timeout(null);

        $this->assertSame('select * from `users`', $builder->toSql());
    }

    public function testTimeoutThrowsExceptionForNegativeValue(): void
    {
        foreach ([0, -1] as $timeout) {
            try {
                $this->getBuilder()->timeout($timeout);
                $this->fail('Expected an invalid timeout exception.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Timeout must be greater than zero.', $exception->getMessage());
            }
        }
    }

    /**
     * Create a query builder with the MySQL grammar.
     */
    protected function getBuilder(): Builder
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'), 'database');

        return new Builder(
            $connection,
            new MySqlGrammar($connection),
            new Processor
        );
    }
}
