<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JsonException;
use PDO;

class DatabaseSQLiteQueryGrammarTest extends TestCase
{
    use VerifiesDoubles;

    public function testUpdateBindingsRejectUnencodableArrays(): void
    {
        $this->expectException(JsonException::class);

        (new SQLiteGrammar(Double::for(Connection::class)->strict()))
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
