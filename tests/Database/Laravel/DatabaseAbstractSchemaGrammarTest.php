<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseAbstractSchemaGrammarTest extends TestCase
{
    public function testCreateDatabase()
    {
        $connection = m::mock(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };

        $this->assertSame('create database "foo"', $grammar->compileCreateDatabase('foo'));
    }

    public function testDropDatabaseIfExists()
    {
        $connection = m::mock(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };

        $this->assertSame('drop database if exists "foo"', $grammar->compileDropDatabaseIfExists('foo'));
    }
}
