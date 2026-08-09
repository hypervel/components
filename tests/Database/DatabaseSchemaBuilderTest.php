<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Database\Schema\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use RuntimeException;

class DatabaseSchemaBuilderTest extends TestCase
{
    public function testCreateDatabase()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $grammar->shouldReceive('compileCreateDatabase')->andReturn('sql');
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('statement')->with('sql')->andReturnTrue();
        $builder = new Builder($connection);

        $this->assertTrue($builder->createDatabase('foo'));
    }

    public function testDropDatabaseIfExists()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $grammar->shouldReceive('compileDropDatabaseIfExists')->andReturn('sql');
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('statement')->with('sql')->andReturnTrue();
        $builder = new Builder($connection);

        $this->assertTrue($builder->dropDatabaseIfExists('foo'));
    }

    public function testExecuteBlueprintCompilesOnceAndExecutesStatementsInOrder(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $blueprint = m::mock(Blueprint::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $blueprint->shouldReceive('toSql')->once()->andReturn(['first statement', 'second statement']);
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new Builder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintThrowsWhenAStatementReturnsFalse(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $blueprint = m::mock(Blueprint::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $blueprint->shouldReceive('toSql')->once()->andReturn(['failed statement', 'unreached statement']);
        $connection->shouldReceive('statement')->once()->with('failed statement')->andReturnFalse();
        $connection->shouldReceive('statement')->never()->with('unreached statement');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute schema statement [failed statement].');

        (new Builder($connection))->executeBlueprint($blueprint);
    }

    public function testWithoutForeignKeyConstraintsNestsAcrossBuilderInstances(): void
    {
        $pdo = m::mock(PDO::class);
        $connection = new Connection($pdo, 'test');
        $grammar = m::mock(Grammar::class);
        $connection->setSchemaGrammar($grammar);

        $grammar->shouldReceive('compileDisableForeignKeyConstraints')->once()->andReturn('disable constraints');
        $grammar->shouldReceive('compileEnableForeignKeyConstraints')->once()->andReturn('enable constraints');
        $pdo->shouldReceive('exec')->once()->with('disable constraints')->andReturn(0)->ordered();
        $pdo->shouldReceive('exec')->once()->with('enable constraints')->andReturn(0)->ordered();

        $outer = new Builder($connection);
        $inner = new Builder($connection);

        $result = $outer->withoutForeignKeyConstraints(
            fn () => $inner->withoutForeignKeyConstraints(fn () => 'result')
        );

        $this->assertSame('result', $result);
    }

    public function testWithoutForeignKeyConstraintsUsesTheStatementPathWhilePretending(): void
    {
        $resolutions = 0;
        $connection = new Connection(
            static function () use (&$resolutions): PDO {
                ++$resolutions;

                return new PDO('sqlite::memory:');
            },
            'test'
        );
        $grammar = m::mock(Grammar::class);
        $connection->setSchemaGrammar($grammar);

        $grammar->shouldReceive('compileDisableForeignKeyConstraints')->once()->andReturn('disable constraints');
        $grammar->shouldReceive('compileEnableForeignKeyConstraints')->once()->andReturn('enable constraints');

        $queries = $connection->pretend(
            fn () => (new Builder($connection))->withoutForeignKeyConstraints(fn () => null)
        );

        $this->assertSame(0, $resolutions);
        $this->assertSame(
            ['disable constraints', 'enable constraints'],
            array_column($queries, 'query')
        );
    }

    public function testWithoutForeignKeyConstraintsMarksTheSessionUnknownWhenRestorationFails(): void
    {
        $pdo = m::mock(PDO::class);
        $connection = new Connection($pdo, 'test');
        $grammar = m::mock(Grammar::class);
        $connection->setSchemaGrammar($grammar);

        $grammar->shouldReceive('compileDisableForeignKeyConstraints')->once()->andReturn('disable constraints');
        $grammar->shouldReceive('compileEnableForeignKeyConstraints')->once()->andReturn('enable constraints');
        $pdo->shouldReceive('exec')->once()->with('disable constraints')->andReturn(0)->ordered();
        $pdo->shouldReceive('exec')->once()->with('enable constraints')->andReturnFalse()->ordered();

        try {
            (new Builder($connection))->withoutForeignKeyConstraints(fn () => null);
            $this->fail('Expected foreign key constraint restoration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Failed to execute schema statement [enable constraints].',
                $exception->getMessage()
            );
        }

        $this->assertTrue($connection->hasUnknownSessionState());
        $this->assertTrue($connection->beginForeignKeyConstraintSuppression());

        $connection->endForeignKeyConstraintSuppression();
    }

    public function testWithoutForeignKeyConstraintsPreservesNativeExceptionChainingWhenCallbackAndRestorationFail(): void
    {
        $pdo = m::mock(PDO::class);
        $connection = new Connection($pdo, 'test');
        $grammar = m::mock(Grammar::class);
        $connection->setSchemaGrammar($grammar);
        $callbackFailure = new RuntimeException('callback failed');
        $restorationFailure = new RuntimeException('restoration failed');

        $grammar->shouldReceive('compileDisableForeignKeyConstraints')->once()->andReturn('disable constraints');
        $grammar->shouldReceive('compileEnableForeignKeyConstraints')->once()->andReturn('enable constraints');
        $pdo->shouldReceive('exec')->once()->with('disable constraints')->andReturn(0)->ordered();
        $pdo->shouldReceive('exec')->once()->with('enable constraints')->andThrow($restorationFailure)->ordered();

        try {
            (new Builder($connection))->withoutForeignKeyConstraints(
                static fn () => throw $callbackFailure
            );
            $this->fail('Expected foreign key constraint restoration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($restorationFailure, $exception);
            $this->assertSame($callbackFailure, $exception->getPrevious());
        }

        $this->assertTrue($connection->hasUnknownSessionState());
    }

    public function testHasTableCorrectlyCallsGrammar()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $processor = m::mock(Processor::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('getPostProcessor')->andReturn($processor);
        $builder = new Builder($connection);
        $grammar->shouldReceive('compileTableExists');
        $grammar->shouldReceive('compileTables')->once()->andReturn('sql');
        $processor->shouldReceive('processTables')->once()->andReturn([['name' => 'prefix_table']]);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('prefix_');
        $connection->shouldReceive('selectFromWriteConnection')->once()->with('sql')->andReturn([['name' => 'prefix_table']]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testTableHasColumns()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = m::mock(Builder::class . '[getColumnListing]', [$connection]);
        $builder->shouldReceive('getColumnListing')->with('users')->twice()->andReturn(['id', 'firstname']);

        $this->assertTrue($builder->hasColumns('users', ['id', 'firstname']));
        $this->assertFalse($builder->hasColumns('users', ['id', 'address']));
    }

    public function testGetColumnTypeAddsPrefix()
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(Grammar::class);
        $processor = m::mock(Processor::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('getPostProcessor')->andReturn($processor);
        $processor->shouldReceive('processColumns')->once()->andReturn([['name' => 'id', 'type_name' => 'integer']]);
        $builder = new Builder($connection);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('prefix_');
        $grammar->shouldReceive('compileColumns')->once()->with(null, 'prefix_users')->andReturn('sql');
        $connection->shouldReceive('selectFromWriteConnection')->once()->with('sql')->andReturn([['name' => 'id', 'type_name' => 'integer']]);

        $this->assertSame('integer', $builder->getColumnType('users', 'id'));
    }
}
