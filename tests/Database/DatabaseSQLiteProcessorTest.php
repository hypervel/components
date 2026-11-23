<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Query\Processors\SQLiteProcessor;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

class DatabaseSQLiteProcessorTest extends TestCase
{
    public function testProcessColumns(): void
    {
        $processor = new SQLiteProcessor;

        $listing = [
            ['name' => 'id', 'type' => 'INTEGER', 'nullable' => '0', 'default' => '', 'primary' => '1', 'extra' => 1, 'auto_increment' => 1],
            ['name' => 'name', 'type' => 'varchar', 'nullable' => '1', 'default' => 'foo', 'primary' => '0', 'extra' => 1, 'auto_increment' => 0],
            ['name' => 'is_active', 'type' => 'tinyint(1)', 'nullable' => '0', 'default' => '1', 'primary' => '0', 'extra' => 1, 'auto_increment' => 0],
            ['name' => 'with/slash', 'type' => 'tinyint(1)', 'nullable' => '0', 'default' => '1', 'primary' => '0', 'extra' => 1, 'auto_increment' => 0],
        ];
        $expected = [
            ['name' => 'id', 'type_name' => 'integer', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => '', 'auto_increment' => true, 'comment' => null, 'generation' => null],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'varchar', 'collation' => null, 'nullable' => true, 'default' => 'foo', 'auto_increment' => false, 'comment' => null, 'generation' => null],
            ['name' => 'is_active', 'type_name' => 'tinyint', 'type' => 'tinyint(1)', 'collation' => null, 'nullable' => false, 'default' => '1', 'auto_increment' => false, 'comment' => null, 'generation' => null],
            ['name' => 'with/slash', 'type_name' => 'tinyint', 'type' => 'tinyint(1)', 'collation' => null, 'nullable' => false, 'default' => '1', 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ];

        $this->assertEquals($expected, $processor->processColumns($listing));

        // convert listing to objects to simulate PDO::FETCH_CLASS
        foreach ($listing as &$row) {
            $row = (object) $row;
        }

        $this->assertEquals($expected, $processor->processColumns($listing));
    }

    #[DataProvider('typeNameProvider')]
    public function testTypeNamesExcludeModifiersButKeepDeclaredNames(string $type, string $typeName): void
    {
        $column = (new SQLiteProcessor)->processColumns([
            ['name' => 'value', 'type' => $type, 'nullable' => '1', 'default' => null, 'primary' => '0', 'extra' => 0, 'auto_increment' => 0],
        ])[0];

        $this->assertSame($typeName, $column['type_name']);
        $this->assertSame(strtolower($type), $column['type']);
    }

    /**
     * Provide declared SQLite types with their names.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function typeNameProvider(): iterable
    {
        yield 'spaced modifiers' => ['varchar (255)', 'varchar'];
        yield 'modifiers' => ['NUMERIC(10, 2)', 'numeric'];
        yield 'falsey quoted name' => ['0', '0'];
        yield 'quoted name with a trailing space' => ['INTEGER ', 'integer '];
        yield 'none' => ['', ''];
    }

    public function testProcessIndexesIncludesPartialFlagAndPreservesSchemaStateMetadata(): void
    {
        $processor = new SQLiteProcessor;
        $results = [
            [
                'name' => 'MixedCase_Index',
                'columns' => '656D61696C',
                'key_columns' => '656D61696C',
                'unique' => 1,
                'primary' => 0,
                'partial' => 1,
                'sql' => 'CREATE UNIQUE INDEX "MixedCase_Index" ON "users" ("email") WHERE "email" IS NOT NULL',
                'origin' => 'c',
                'reconstructible' => 0,
                'collations' => '42494E415259',
                'descending' => '0',
            ],
            [
                'name' => 'users_expression_index',
                'columns' => null,
                'key_columns' => '656D61696C,-',
                'unique' => 1,
                'primary' => 0,
                'partial' => 0,
                'sql' => 'CREATE UNIQUE INDEX users_expression_index ON users (email, lower(name))',
                'origin' => 'c',
                'reconstructible' => 0,
                'collations' => null,
                'descending' => null,
            ],
        ];

        $this->assertSame([
            [
                'name' => 'mixedcase_index',
                'columns' => ['email'],
                'key_columns' => ['email'],
                'type' => null,
                'unique' => true,
                'primary' => false,
                'partial' => true,
            ],
            [
                'name' => 'users_expression_index',
                'columns' => [],
                'key_columns' => ['email', null],
                'type' => null,
                'unique' => true,
                'primary' => false,
                'partial' => false,
            ],
        ], $processor->processIndexes($results));

        $this->assertSame([
            [
                'name' => 'mixedcase_index',
                'physical_name' => 'MixedCase_Index',
                'columns' => ['email'],
                'key_columns' => ['email'],
                'type' => null,
                'unique' => true,
                'primary' => false,
                'partial' => true,
                'sql' => 'CREATE UNIQUE INDEX "MixedCase_Index" ON "users" ("email") WHERE "email" IS NOT NULL',
                'origin' => 'c',
                'reconstructible' => false,
                'collations' => ['BINARY'],
                'descending' => [false],
            ],
            [
                'name' => 'users_expression_index',
                'physical_name' => 'users_expression_index',
                'columns' => [],
                'key_columns' => ['email', null],
                'type' => null,
                'unique' => true,
                'primary' => false,
                'partial' => false,
                'sql' => 'CREATE UNIQUE INDEX users_expression_index ON users (email, lower(name))',
                'origin' => 'c',
                'reconstructible' => false,
                'collations' => null,
                'descending' => null,
            ],
        ], $processor->processIndexesForSchemaState($results));
    }

    public function testProcessIndexMetadataPreservesCommaBearingValues(): void
    {
        $processor = new SQLiteProcessor;
        $results = [[
            'name' => 'sqlite_autoindex_contacts_1',
            'columns' => '656D61696C2C61646472657373',
            'key_columns' => '656D61696C2C61646472657373',
            'unique' => 1,
            'primary' => 0,
            'partial' => 0,
            'sql' => null,
            'origin' => 'u',
            'reconstructible' => 0,
            'collations' => '636F6D6D612C6E616D65',
            'descending' => '1',
        ]];

        $this->assertSame([
            'name' => 'sqlite_autoindex_contacts_1',
            'physical_name' => 'sqlite_autoindex_contacts_1',
            'columns' => ['email,address'],
            'key_columns' => ['email,address'],
            'type' => null,
            'unique' => true,
            'primary' => false,
            'partial' => false,
            'sql' => null,
            'origin' => 'u',
            'reconstructible' => false,
            'collations' => ['comma,name'],
            'descending' => [true],
        ], $processor->processIndexesForSchemaState($results)[0]);

        $this->assertSame(['email,address'], $processor->processIndexes($results)[0]['columns']);
    }

    public function testProcessIndexMetadataRejectsInvalidHexadecimalValues(): void
    {
        $processor = new SQLiteProcessor;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('The SQLite schema metadata contains invalid hexadecimal text.');

        $processor->processIndexesForSchemaState([[
            'name' => 'contacts_index',
            'columns' => 'not-hexadecimal',
            'key_columns' => 'not-hexadecimal',
            'unique' => 0,
            'primary' => 0,
            'partial' => 0,
            'sql' => 'CREATE INDEX contacts_index ON contacts (email)',
            'origin' => 'c',
            'reconstructible' => 1,
            'collations' => '42494E415259',
            'descending' => '0',
        ]]);
    }

    /**
     * Process composite primary-key indexes as lists.
     */
    public function testProcessCompositePrimaryKeyIndexesAsLists(): void
    {
        $processor = new SQLiteProcessor;
        $results = [
            [
                'name' => 'users_email_index',
                'columns' => '656D61696C',
                'key_columns' => '656D61696C',
                'unique' => 0,
                'primary' => 0,
                'partial' => 0,
                'sql' => 'CREATE INDEX "users_email_index" ON "users" ("email")',
                'origin' => 'c',
                'reconstructible' => 1,
                'collations' => '42494E415259',
                'descending' => '0',
            ],
            [
                'name' => 'primary',
                'columns' => '74656E616E745F6964,6964',
                'key_columns' => '74656E616E745F6964,6964',
                'unique' => 1,
                'primary' => 1,
                'partial' => 0,
                'sql' => null,
                'origin' => 'pk',
                'reconstructible' => 1,
                'collations' => null,
                'descending' => null,
            ],
            [
                'name' => 'sqlite_autoindex_users_1',
                'columns' => '74656E616E745F6964,6964',
                'key_columns' => '74656E616E745F6964,6964',
                'unique' => 1,
                'primary' => 1,
                'partial' => 0,
                'sql' => null,
                'origin' => 'pk',
                'reconstructible' => 1,
                'collations' => '42494E415259,42494E415259',
                'descending' => '0,0',
            ],
        ];

        $indexes = $processor->processIndexesForSchemaState($results);

        $this->assertTrue(array_is_list($indexes));
        $this->assertSame(['users_email_index', 'sqlite_autoindex_users_1'], array_column($indexes, 'name'));

        $publicIndexes = $processor->processIndexes($results);

        $this->assertTrue(array_is_list($publicIndexes));
        $this->assertSame(['users_email_index', 'sqlite_autoindex_users_1'], array_column($publicIndexes, 'name'));
    }

    public function testForeignKeyNamesPreserveCommasAndUnresolvedTargets(): void
    {
        $processor = new SQLiteProcessor;
        $metadata = ['columns' => bin2hex('from,a') . ',' . bin2hex('from,b'), 'foreign_schema' => 'main', 'foreign_table' => 'parents', 'on_update' => 'CASCADE', 'on_delete' => 'RESTRICT', 'implicit' => 1];
        $results = [
            $metadata + ['foreign_columns' => bin2hex('id,one') . ',' . bin2hex('id,two')],
            $metadata + ['foreign_columns' => null],
        ];
        $keys = $processor->processForeignKeys($results);

        $this->assertSame(['from,a', 'from,b'], $keys[0]['columns']);
        $this->assertSame(['id,one', 'id,two'], $keys[0]['foreign_columns']);
        $this->assertSame([], $keys[1]['foreign_columns']);
        $this->assertArrayNotHasKey('implicit', $keys[0]);
        $this->assertSame($keys[0] + ['implicit' => true], $processor->processForeignKeysForSchemaState($results)[0]);
    }
}
