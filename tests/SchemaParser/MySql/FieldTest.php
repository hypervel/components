<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FieldTest extends TestCase
{
    public function testBasicFields(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT)');
        $this->assertEquals(count($tbl['fields']), 1);
        $this->assertEquals($tbl['fields'][0]['type'], 'INT');
        $this->assertEquals($tbl['fields'][0]['name'], 'bar');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz TINYINT)');
        $this->assertEquals(count($tbl['fields']), 2);
        $this->assertEquals($tbl['fields'][0]['type'], 'INT');
        $this->assertEquals($tbl['fields'][0]['name'], 'bar');
        $this->assertEquals($tbl['fields'][1]['type'], 'TINYINT');
        $this->assertEquals($tbl['fields'][1]['name'], 'baz');
    }

    public function testSimpleFields(): void
    {
        # DATE
        # YEAR
        # TINYBLOB
        # BLOB
        # MEDIUMBLOB
        # LONGBLOB
        # JSON
        # UUID

        $tbl = $this->get_first_table('CREATE TABLE foo (bar DATE)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'DATE',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar UUID)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'UUID',
            ],
        ]);
    }

    public function testInts(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar TINYINT)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'TINYINT',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar smallint (4))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'SMALLINT',
                'length' => '4',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar MEDIUMINT UNSIGNED)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'MEDIUMINT',
                'unsigned' => true,
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT ZEROFILL)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'INT',
                'zerofill' => true,
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar BIGINT(20) UNSIGNED ZEROFILL)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'BIGINT',
                'length' => '20',
                'unsigned' => true,
                'zerofill' => true,
            ],
        ]);
    }

    public function testFloats(): void
    {
        # REAL[(length,decimals)] [UNSIGNED] [ZEROFILL]
        # DOUBLE[(length,decimals)] [UNSIGNED] [ZEROFILL]
        # FLOAT[(length,decimals)] [UNSIGNED] [ZEROFILL]

        $tbl = $this->get_first_table('CREATE TABLE foo (bar REAL)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'REAL',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar double (1,2))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'DOUBLE',
                'length' => 1,
                'decimals' => 2,
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar Float(3,4) UNSIGNED ZEROFILL)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'FLOAT',
                'length' => 3,
                'decimals' => 4,
                'unsigned' => true,
                'zerofill' => true,
            ],
        ]);
    }

    public function testNumerics(): void
    {
        # DECIMAL[(length[,decimals])] [UNSIGNED] [ZEROFILL]
        # NUMERIC[(length[,decimals])] [UNSIGNED] [ZEROFILL]

        $tbl = $this->get_first_table('CREATE TABLE foo (bar Decimal)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'DECIMAL',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar DECIMAL(1) UNSIGNED)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'DECIMAL',
                'length' => 1,
                'unsigned' => true,
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar NUMERIC(1,2) ZEROFILL)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'NUMERIC',
                'length' => 1,
                'decimals' => 2,
                'zerofill' => true,
            ],
        ]);
    }

    public function testTimes(): void
    {
        # TIME[(fsp)]
        # TIMESTAMP[(fsp)]
        # DATETIME[(fsp)]

        $tbl = $this->get_first_table('CREATE TABLE foo (bar TIME)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'TIME',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar TIMESTAMP(6))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'TIMESTAMP',
                'fsp' => 6,
            ],
        ]);
    }

    public function testBits(): void
    {
        # BIT[(length)]

        $tbl = $this->get_first_table('CREATE TABLE foo (bar bit)');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'BIT',
            ],
        ]);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar BIT (999))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'BIT',
                'length' => 999,
            ],
        ]);
    }

    public function testChars(): void
    {
        # CHAR[(length)] [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
        # VARCHAR(length) [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
        # BINARY[(length)]
        # VARBINARY(length)

        $fields = $this->get_fields('bar CHAR BINARY CHARACTER SET `utf19`');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'CHAR',
                'binary' => true,
                'character_set' => 'utf19',
            ],
        ], $fields);

        $fields = $this->get_fields('bar CHAR(15) CHARACTER SET `utf19` COLLATE `utf19_awesome`');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'CHAR',
                'length' => 15,
                'character_set' => 'utf19',
                'collation' => '`utf19_awesome`',
            ],
        ], $fields);

        $fields = $this->get_fields('bar VARCHAR(255)');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'VARCHAR',
                'length' => 255,
            ],
        ], $fields);

        $fields = $this->get_fields('bar BINARY');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'BINARY',
            ],
        ], $fields);

        $fields = $this->get_fields('bar BINARY(66)');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'BINARY',
                'length' => 66,
            ],
        ], $fields);

        $fields = $this->get_fields('bar VARBINARY(1024)');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'VARBINARY',
                'length' => 1024,
            ],
        ], $fields);
    }

    public function testTexts(): void
    {
        foreach (['TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT'] as $type) {
            $fields = $this->get_fields("body {$type} CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $this->assertSame($type, $fields[0]['type']);
            $this->assertSame('utf8mb4', $fields[0]['character_set']);
            $this->assertSame('utf8mb4_bin', $fields[0]['collation']);
        }
    }

    public function testSets(): void
    {
        # ENUM(value1,value2,value3,...) [CHARACTER SET charset_name] [COLLATE collation_name]
        # SET(value1,value2,value3,...) [CHARACTER SET charset_name] [COLLATE collation_name]

        # a `\'` inside a string quoted with `'`
        $tbl = $this->get_first_table("CREATE TABLE foo (bar ENUM('hel\\'lo'))");
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ["hel'lo"],
            ],
        ]);

        # a `\"` inside a string quoted with `"`
        $tbl = $this->get_first_table('CREATE TABLE foo (bar ENUM("hel\"lo"))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ['hel"lo'],
            ],
        ]);

        # a `\'` inside a string quoted with `"`
        $tbl = $this->get_first_table("CREATE TABLE foo (bar ENUM(\"hel\\'lo\"))");
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ["hel'lo"],
            ],
        ]);

        # a `\"` inside a string quoted with `'`
        $tbl = $this->get_first_table('CREATE TABLE foo (bar ENUM(\'hel\"lo\'))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ['hel"lo'],
            ],
        ]);

        # a `''` inside a string quoted with `'`
        $tbl = $this->get_first_table("CREATE TABLE foo (bar ENUM('hel''lo'))");
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ["hel'lo"],
            ],
        ]);

        # a `""` inside a string quoted with `"`
        $tbl = $this->get_first_table('CREATE TABLE foo (bar ENUM("hel""lo"))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ['hel"lo'],
            ],
        ]);

        # a `''` inside a string quoted with `"`
        $tbl = $this->get_first_table("CREATE TABLE foo (bar ENUM(\"hel''lo\"))");
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ["hel''lo"],
            ],
        ]);

        # a `""` inside a string quoted with `'`
        $tbl = $this->get_first_table('CREATE TABLE foo (bar ENUM(\'hel""lo\'))');
        $this->assertEquals($tbl['fields'], [
            [
                'name' => 'bar',
                'type' => 'ENUM',
                'values' => ['hel""lo'],
            ],
        ]);

        $fields = $this->get_fields("choices SET('one', 'two') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        $this->assertSame(['one', 'two'], $fields[0]['values']);
        $this->assertSame('utf8mb4', $fields[0]['character_set']);
        $this->assertSame('utf8mb4_bin', $fields[0]['collation']);
    }

    public function testSpatials(): void
    {
        foreach (['GEOMETRY', 'POINT', 'LINESTRING', 'POLYGON', 'MULTIPOINT', 'MULTILINESTRING', 'MULTIPOLYGON', 'GEOMETRYCOLLECTION', 'GEOMCOLLECTION'] as $type) {
            $this->assertSame($type, $this->get_fields("shape {$type}")[0]['type']);
        }
    }

    public function testJson(): void
    {
        $this->assertSame('JSON', $this->get_fields('payload JSON')[0]['type']);
    }

    public function testFieldOptions(): void
    {
        # data_type
        # [NOT NULL | NULL]
        # [DEFAULT default_value]
        # [AUTO_INCREMENT]
        # [UNIQUE [KEY] | [PRIMARY] KEY]
        # [COMMENT 'string']
        # [COLUMN_FORMAT {FIXED|DYNAMIC|DEFAULT}]
        # [STORAGE {DISK|MEMORY|DEFAULT}]
        # [reference_definition]

        $fields = $this->get_fields('bar VARCHAR(255) DEFAULT NULL');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'VARCHAR',
                'length' => 255,
                'default' => 'NULL',
            ],
        ], $fields);

        $fields = $this->get_fields('bar INT NOT NULL DEFAULT -1');
        $this->assertEquals([
            [
                'name' => 'bar',
                'type' => 'INT',
                'default' => '-1',
                'null' => false,
            ],
        ], $fields);

        $fields = $this->get_fields("ucount int NOT NULL comment 'user count'");
        $this->assertEquals('user count', $fields[0]['comment']);
    }

    public function testVirtualOptions(): void
    {
        $fields = $this->get_fields("total int GENERATED ALWAYS AS (price * (quantity + 1)) STORED COMMENT 'calculated', label varchar(255) AS (concat('a , b', total)) VIRTUAL");
        $this->assertSame('(price * (quantity + 1))', $fields[0]['generation']);
        $this->assertSame('stored', $fields[0]['generation_type']);
        $this->assertSame('calculated', $fields[0]['comment']);
        $this->assertSame("(concat('a , b', total))", $fields[1]['generation']);
        $this->assertSame('virtual', $fields[1]['generation_type']);
    }

    #[DataProvider('expressionDefaults')]
    public function testDefaultsRetainSqlText(string $expression): void
    {
        $fields = $this->get_fields("value varchar(255) NOT NULL DEFAULT {$expression} COMMENT 'value'");

        $this->assertSame($expression, $fields[0]['default']);
        $this->assertFalse($fields[0]['null']);
        $this->assertSame('value', $fields[0]['comment']);
        $this->assertArrayNotHasKey('more', $fields[0]);
    }

    public static function expressionDefaults(): array
    {
        return [
            ['NULL'],
            ["'NULL'"],
            ["'a  b'"],
            ["b'101'"],
            ["X'0a1b'"],
            ["N'national'"],
            ["_utf8mb4 'a  b'"],
            ['CURRENT_TIMESTAMP(6)'],
            ['- 1'],
            ['-1'],
            ['+1e-5'],
            ['-(1 + 2)'],
            ["(concat('a, b', (1 + 2)))"],
        ];
    }

    public function testTimestampUpdateAndUnorderedColumnOptions(): void
    {
        $fields = $this->get_fields("updated_at timestamp(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL, id int COMMENT 'identifier' AUTO_INCREMENT PRIMARY KEY INVISIBLE");

        $this->assertSame('CURRENT_TIMESTAMP(6)', $fields[0]['on_update']);
        $this->assertFalse($fields[0]['null']);
        $this->assertTrue($fields[1]['auto_increment']);
        $this->assertTrue($fields[1]['primary']);
        $this->assertTrue($fields[1]['invisible']);
        $this->assertSame('identifier', $fields[1]['comment']);
    }

    public function testDecodedLabelsCommentsAndIdentifiers(): void
    {
        $fields = $this->get_fields(<<<'SQL'
            `a``b` ENUM('nul\0', 'backspace\b', 'control\Z', 'slash\\', 'percent\%', 'underscore\_', 'unknown\q') COMMENT 'path\\name'
            SQL);

        $this->assertSame('a`b', $fields[0]['name']);
        $this->assertSame(["nul\0", "backspace\x08", "control\x1a", 'slash\\', 'percent\%', 'underscore\_', 'unknownq'], $fields[0]['values']);
        $this->assertSame('path\name', $fields[0]['comment']);
    }

    public function testAdditionalTypesAndOpaqueModifiers(): void
    {
        $fields = $this->get_fields('embedding VECTOR(1536), ipv4 INET4, ipv6 INET6, custom CUSTOM_TYPE(10, 2)');

        $this->assertSame('1536', $fields[0]['length']);
        $this->assertSame('INET4', $fields[1]['type']);
        $this->assertSame('INET6', $fields[2]['type']);
        $this->assertSame('CUSTOM_TYPE', $fields[3]['type']);
        $this->assertSame(['10', '2'], $fields[3]['modifiers']);
    }

    private function get_fields(string $indexes): array
    {
        $sql = "CREATE TABLE foo ({$indexes})";
        $tbl = $this->get_first_table($sql);
        return $tbl['fields'];
    }

    private function get_first_table(string $str): array
    {
        $obj = new SqlParser;
        $obj->parse($str);

        $tables = array_keys($obj->tables);
        $first_key = $tables[0];

        return $obj->tables[$first_key];
    }
}
