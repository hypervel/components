<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Query\Processors\MySqlProcessor;
use Hypervel\Tests\TestCase;

class DatabaseMySqlProcessorTest extends TestCase
{
    public function testProcessColumns(): void
    {
        $processor = new MySqlProcessor;
        $listing = [
            ['name' => 'id', 'type_name' => 'bigint', 'type' => 'bigint', 'collation' => 'collate', 'nullable' => 'YES', 'default' => '', 'extra' => 'auto_increment', 'comment' => 'bar', 'expression' => null],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'varchar(100)', 'collation' => 'collate', 'nullable' => 'NO', 'default' => 'foo', 'extra' => '', 'comment' => '', 'expression' => null],
            ['name' => 'email', 'type_name' => 'varchar', 'type' => 'varchar(100)', 'collation' => 'collate', 'nullable' => 'YES', 'default' => 'NULL', 'extra' => 'on update CURRENT_TIMESTAMP', 'comment' => 'NULL', 'expression' => null],
        ];
        $expected = [
            ['name' => 'id', 'type_name' => 'bigint', 'type' => 'bigint', 'collation' => 'collate', 'nullable' => true, 'default' => '', 'auto_increment' => true, 'comment' => 'bar', 'generation' => null],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'varchar(100)', 'collation' => 'collate', 'nullable' => false, 'default' => 'foo', 'auto_increment' => false, 'comment' => null, 'generation' => null],
            ['name' => 'email', 'type_name' => 'varchar', 'type' => 'varchar(100)', 'collation' => 'collate', 'nullable' => true, 'default' => 'NULL', 'auto_increment' => false, 'comment' => 'NULL', 'generation' => null],
        ];
        $this->assertEquals($expected, $processor->processColumns($listing));

        // convert listing to objects to simulate PDO::FETCH_CLASS
        foreach ($listing as &$row) {
            $row = (object) $row;
        }

        $this->assertEquals($expected, $processor->processColumns($listing));
    }

    public function testProcessIndexesReportsFullIndexes(): void
    {
        $processor = new MySqlProcessor;

        $this->assertSame([
            [
                'name' => 'users_active_index',
                'columns' => ['account_id', 'archived_at'],
                'key_columns' => ['account_id', 'archived_at'],
                'type' => 'btree',
                'unique' => false,
                'primary' => false,
                'partial' => false,
            ],
            [
                'name' => 'primary',
                'columns' => ['id'],
                'key_columns' => ['id'],
                'type' => 'btree',
                'unique' => true,
                'primary' => true,
                'partial' => false,
            ],
        ], $processor->processIndexes([
            ['name' => 'Users_Active_Index', 'column_name' => 'account_id', 'type' => 'BTREE', 'unique' => false],
            ['name' => 'Users_Active_Index', 'column_name' => 'archived_at', 'type' => 'BTREE', 'unique' => false],
            ['name' => 'PRIMARY', 'column_name' => 'id', 'type' => 'BTREE', 'unique' => true],
        ]));
    }

    public function testIndexKeysPreserveCommasAndExpressionPositions(): void
    {
        $processor = new MySqlProcessor;
        $indexes = $processor->processIndexes([
            (object) ['name' => 'mixed', 'column_name' => 'tenant,id', 'type' => 'BTREE', 'unique' => true],
            (object) ['name' => 'mixed', 'column_name' => null, 'type' => 'BTREE', 'unique' => true],
            (object) ['name' => 'plain', 'column_name' => 'tenant,id', 'type' => 'BTREE', 'unique' => false],
        ]);

        $this->assertSame([], $indexes[0]['columns']);
        $this->assertSame(['tenant,id', null], $indexes[0]['key_columns']);
        $this->assertSame(['tenant,id'], $indexes[1]['columns']);
    }

    public function testForeignKeyPairsPreserveNamesAndOrder(): void
    {
        $processor = new MySqlProcessor;
        $metadata = ['name' => 'parent_fk', 'foreign_schema' => 'app', 'foreign_table' => 'parents', 'on_update' => 'CASCADE', 'on_delete' => 'RESTRICT'];

        $this->assertSame([
            ['name' => 'parent_fk', 'columns' => ['tenant,id', 'parent_id'], 'foreign_schema' => 'app', 'foreign_table' => 'parents', 'foreign_columns' => ['tenant,id', 'id'], 'on_update' => 'cascade', 'on_delete' => 'restrict'],
        ], $processor->processForeignKeys([
            $metadata + ['column_name' => 'tenant,id', 'foreign_column_name' => 'tenant,id'],
            $metadata + ['column_name' => 'parent_id', 'foreign_column_name' => 'id'],
        ]));
    }
}
