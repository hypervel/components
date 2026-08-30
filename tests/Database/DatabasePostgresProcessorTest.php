<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Tests\TestCase;

class DatabasePostgresProcessorTest extends TestCase
{
    public function testProcessColumns()
    {
        $processor = new PostgresProcessor;

        $listing = [
            ['name' => 'id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => '', 'nullable' => true, 'default' => "nextval('employee_id_seq'::regclass)", 'comment' => '', 'generated' => false],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'character varying(100)', 'collation' => 'collate', 'nullable' => false, 'default' => '', 'comment' => 'foo', 'generated' => false],
            ['name' => 'balance', 'type_name' => 'numeric', 'type' => 'numeric(8,2)', 'collation' => '', 'nullable' => true, 'default' => '4', 'comment' => 'NULL', 'generated' => false],
            ['name' => 'birth_date', 'type_name' => 'timestamp', 'type' => 'timestamp(6) without time zone', 'collation' => '', 'nullable' => false, 'default' => '', 'comment' => '', 'generated' => false],
        ];
        $expected = [
            ['name' => 'id', 'type_name' => 'int4', 'type' => 'integer', 'collation' => '', 'nullable' => true, 'default' => "nextval('employee_id_seq'::regclass)", 'auto_increment' => true, 'comment' => '', 'generation' => null],
            ['name' => 'name', 'type_name' => 'varchar', 'type' => 'character varying(100)', 'collation' => 'collate', 'nullable' => false, 'default' => '', 'auto_increment' => false, 'comment' => 'foo', 'generation' => null],
            ['name' => 'balance', 'type_name' => 'numeric', 'type' => 'numeric(8,2)', 'collation' => '', 'nullable' => true, 'default' => '4', 'auto_increment' => false, 'comment' => 'NULL', 'generation' => null],
            ['name' => 'birth_date', 'type_name' => 'timestamp', 'type' => 'timestamp(6) without time zone', 'collation' => '', 'nullable' => false, 'default' => '', 'auto_increment' => false, 'comment' => '', 'generation' => null],
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
                'type' => 'btree',
                'unique' => false,
                'primary' => false,
                'partial' => true,
            ],
            [
                'name' => 'users_pkey',
                'columns' => ['id'],
                'type' => 'btree',
                'unique' => true,
                'primary' => true,
                'partial' => false,
            ],
        ], $processor->processIndexes([
            ['name' => 'Users_Active_Index', 'columns' => 'account_id,archived_at', 'type' => 'BTREE', 'unique' => false, 'primary' => false, 'partial' => true],
            ['name' => 'Users_PKey', 'columns' => 'id', 'type' => 'BTREE', 'unique' => true, 'primary' => true, 'partial' => false],
        ]));
    }
}
