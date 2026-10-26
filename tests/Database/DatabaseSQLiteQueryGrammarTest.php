<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Tests\TestCase;
use JsonException;
use Mockery as m;
use PDO;

class DatabaseSQLiteQueryGrammarTest extends TestCase
{
    public function testUpdateBindingsRejectUnencodableArrays(): void
    {
        $this->expectException(JsonException::class);

        (new SQLiteGrammar(m::mock(Connection::class)))
            ->prepareBindingsForUpdate([], ['payload' => [NAN]]);
    }

    public function testToRawSql(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $grammar = new SQLiteGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'Hello\'\'World?\' IS NOT NULL AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'Hello\'\'World?\' IS NOT NULL AND "email" = \'foo\'', $query);
    }
}
