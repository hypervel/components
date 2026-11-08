<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Tests\TestCase;

class DatabasePostgresProcessorTest extends TestCase
{
    public function testProcessColumns(): void
    {
        $processor = new PostgresProcessor;

        $listing = [
            ['name' => 'id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => '', 'nullable' => true, 'default' => "nextval('employee_id_seq'::regclass)", 'comment' => '', 'generated' => false],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'character varying(100)', 'collation' => 'collate', 'nullable' => false, 'default' => '', 'comment' => 'foo', 'generated' => false],
            ['name' => 'balance', 'type_name' => 'numeric', 'type' => 'numeric(8,2)', 'collation' => '', 'nullable' => true, 'default' => '4', 'comment' => 'NULL', 'generated' => false],
            ['name' => 'birth_date', 'type_name' => 'timestamp', 'type' => 'timestamp(6) without time zone', 'collation' => '', 'nullable' => false, 'default' => '', 'comment' => '', 'generated' => false],
            ['name' => 'always_id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => null, 'comment' => null, 'generated' => '', 'identity' => 'a'],
            ['name' => 'default_id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => null, 'comment' => null, 'generated' => '', 'identity' => 'd'],
            ['name' => 'plain_id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => true, 'default' => null, 'comment' => null, 'generated' => '', 'identity' => ''],
        ];
        $expected = [
            ['name' => 'id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => '', 'nullable' => true, 'default' => "nextval('employee_id_seq'::regclass)", 'auto_increment' => true, 'comment' => '', 'generation' => null],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'character varying(100)', 'collation' => 'collate', 'nullable' => false, 'default' => '', 'auto_increment' => false, 'comment' => 'foo', 'generation' => null],
            ['name' => 'balance', 'type_name' => 'numeric', 'type' => 'numeric(8,2)', 'collation' => '', 'nullable' => true, 'default' => '4', 'auto_increment' => false, 'comment' => 'NULL', 'generation' => null],
            ['name' => 'birth_date', 'type_name' => 'timestamp', 'type' => 'timestamp(6) without time zone', 'collation' => '', 'nullable' => false, 'default' => '', 'auto_increment' => false, 'comment' => '', 'generation' => null],
            ['name' => 'always_id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => true, 'comment' => null, 'generation' => null],
            ['name' => 'default_id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => false, 'default' => null, 'auto_increment' => true, 'comment' => null, 'generation' => null],
            ['name' => 'plain_id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => null, 'nullable' => true, 'default' => null, 'auto_increment' => false, 'comment' => null, 'generation' => null],
        ];

        $this->assertEquals($expected, $processor->processColumns($listing));

        // convert listing to objects to simulate PDO::FETCH_CLASS
        foreach ($listing as &$row) {
            $row = (object) $row;
        }

        $this->assertEquals($expected, $processor->processColumns($listing));
    }

    public function testProcessIndexesIncludesPartialMetadata(): void
    {
        $processor = new PostgresProcessor;

        $this->assertSame([
            [
                'name' => 'users_active_index',
                'columns' => ['account_id', 'archived_at'],
                'key_columns' => ['account_id', 'archived_at'],
                'type' => 'btree',
                'unique' => false,
                'primary' => false,
                'partial' => true,
            ],
            [
                'name' => 'users_pkey',
                'columns' => ['id'],
                'key_columns' => ['id'],
                'type' => 'btree',
                'unique' => true,
                'primary' => true,
                'partial' => false,
            ],
        ], $processor->processIndexes([
            ['name' => 'Users_Active_Index', 'column_name' => 'account_id', 'type' => 'BTREE', 'unique' => false, 'primary' => false, 'partial' => true],
            ['name' => 'Users_Active_Index', 'column_name' => 'archived_at', 'type' => 'BTREE', 'unique' => false, 'primary' => false, 'partial' => true],
            ['name' => 'Users_PKey', 'column_name' => 'id', 'type' => 'BTREE', 'unique' => true, 'primary' => true, 'partial' => false],
        ]));
    }

    public function testIndexKeysPreserveCommasAndExpressionPositions(): void
    {
        $processor = new PostgresProcessor;
        $metadata = ['type' => 'BTREE', 'unique' => true, 'primary' => false, 'partial' => false];
        $indexes = $processor->processIndexes([
            (object) ($metadata + ['name' => 'mixed', 'column_name' => 'tenant,id']),
            (object) ($metadata + ['name' => 'mixed', 'column_name' => null]),
            (object) ($metadata + ['name' => 'plain', 'column_name' => 'tenant,id']),
        ]);

        $this->assertSame([], $indexes[0]['columns']);
        $this->assertSame(['tenant,id', null], $indexes[0]['key_columns']);
        $this->assertSame(['tenant,id'], $indexes[1]['columns']);
    }

    public function testForeignKeyPairsPreserveNamesAndOrder(): void
    {
        $processor = new PostgresProcessor;
        $metadata = ['name' => 'parent_fk', 'foreign_schema' => 'public', 'foreign_table' => 'parents', 'on_update' => 'c', 'on_delete' => 'r'];

        $this->assertSame([
            ['name' => 'parent_fk', 'columns' => ['tenant,id', 'parent_id'], 'foreign_schema' => 'public', 'foreign_table' => 'parents', 'foreign_columns' => ['tenant,id', 'id'], 'on_update' => 'cascade', 'on_delete' => 'restrict'],
        ], $processor->processForeignKeys([
            $metadata + ['column_name' => 'tenant,id', 'foreign_column_name' => 'tenant,id'],
            $metadata + ['column_name' => 'parent_id', 'foreign_column_name' => 'id'],
        ]));
    }
}
