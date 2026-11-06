<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class IndexTest extends TestCase
{
    public function testBasicIndex(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX(bar))');
        $this->assertEquals(count($tbl['indexes']), 1);
        $this->assertEquals($tbl['indexes'][0]['type'], 'INDEX');
        $this->assertEquals(count($tbl['indexes'][0]['cols']), 1);
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
    }

    public function testIndexTypes(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'INDEX');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, KEY (bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'INDEX');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, UNIQUE(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'UNIQUE');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, UNIQUE INDEX(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'UNIQUE');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, UNIQUE KEY (bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'UNIQUE');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, PRIMARY KEY(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'PRIMARY');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FULLTEXT(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'FULLTEXT');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FULLTEXT INDEX(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'FULLTEXT');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FULLTEXT KEY(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'FULLTEXT');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, SPATIAL(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'SPATIAL');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, SPATIAL INDEX(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'SPATIAL');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, SPATIAL KEY(bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'SPATIAL');

        $table = $this->get_first_table('CREATE TABLE locations (location point NOT NULL, spatial by_location (location))');
        $this->assertSame('SPATIAL', $table['indexes'][0]['type']);

        $table = $this->get_first_table('CREATE TABLE embeddings (embedding vector(3) NOT NULL, vector index by_embedding (embedding))');
        $this->assertSame('VECTOR', $table['indexes'][0]['type']);
    }

    public function testConstraints(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, CONSTRAINT UNIQUE INDEX(bar))');
        $this->assertEquals($tbl['indexes'][0]['constraint'], true);
        $this->assertEquals(array_key_exists('constraint_name', $tbl['indexes'][0]), false);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, CONSTRAINT `qux` UNIQUE INDEX(bar))');
        $this->assertEquals($tbl['indexes'][0]['constraint'], true);
        $this->assertEquals($tbl['indexes'][0]['constraint_name'], 'qux');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, CONSTRAINT `qux` PRIMARY KEY(bar))');
        $this->assertEquals($tbl['indexes'][0]['constraint'], true);
        $this->assertEquals($tbl['indexes'][0]['constraint_name'], 'qux');
    }

    public function testIndexNames(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX(bar))');
        $this->assertEquals(array_key_exists('name', $tbl['indexes'][0]), false);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX baz(bar))');
        $this->assertEquals($tbl['indexes'][0]['name'], 'baz');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FULLTEXT KEY `baz` (bar))');
        $this->assertEquals($tbl['indexes'][0]['name'], 'baz');
    }

    public function testIndexModes(): void
    {
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX(bar))');
        $this->assertEquals(array_key_exists('mode', $tbl['indexes'][0]), false);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX  USING BTREE(bar))');
        $this->assertEquals($tbl['indexes'][0]['mode'], 'BTREE');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, INDEX  USING HASH (bar))');
        $this->assertEquals($tbl['indexes'][0]['mode'], 'HASH');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, PRIMARY KEY USING HASH (bar))');
        $this->assertEquals($tbl['indexes'][0]['mode'], 'HASH');

        # since fulltext and spatial indexes don't have a type/mode, this is invalid
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FULLTEXT USING HASH(bar))');
        $this->assertEquals(array_key_exists('mode', $tbl['indexes'][0]), false);
    }

    public function testIndexCols(): void
    {
        # single column
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar))');
        $this->assertEquals(count($tbl['indexes'][0]['cols']), 1);
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');

        # multi column
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar, baz))');
        $this->assertEquals(count($tbl['indexes'][0]['cols']), 2);
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals($tbl['indexes'][0]['cols'][1]['name'], 'baz');

        # length (or not)
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar))');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals(array_key_exists('length', $tbl['indexes'][0]['cols'][0]), false);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar (100)))');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['length'], '100');

        # direction (or not)
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar))');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals(array_key_exists('direction', $tbl['indexes'][0]['cols'][0]), false);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar ASC))');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['direction'], 'ASC');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar desc))');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['direction'], 'DESC');

        # everything
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar (10) ASC, baz DESC))');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['name'], 'bar');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['length'], '10');
        $this->assertEquals($tbl['indexes'][0]['cols'][0]['direction'], 'ASC');
        $this->assertEquals($tbl['indexes'][0]['cols'][1]['name'], 'baz');
        $this->assertEquals($tbl['indexes'][0]['cols'][1]['direction'], 'DESC');
    }

    public function testIndexOptions(): void
    {
        # key block size
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) KEY_BLOCK_SIZE 4)');
        $this->assertEquals($tbl['indexes'][0]['key_block_size'], '4');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) KEY_BLOCK_SIZE=4)');
        $this->assertEquals($tbl['indexes'][0]['key_block_size'], '4');

        # key mode
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) USING BTREE)');
        $this->assertEquals($tbl['indexes'][0]['mode'], 'BTREE');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) USING HASH)');
        $this->assertEquals($tbl['indexes'][0]['mode'], 'HASH');

        # parser
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) WITH PARSER foo)');
        $this->assertEquals($tbl['indexes'][0]['parser'], 'foo');

        # comment
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) COMMENT "hello world")');
        $this->assertEquals($tbl['indexes'][0]['comment'], 'hello world');

        # everything
        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, baz INT, PRIMARY KEY (bar) comment "hello world" USING HASH key_block_size=4 WITH PARSER foo)');
        $this->assertEquals($tbl['indexes'][0]['comment'], 'hello world');
        $this->assertEquals($tbl['indexes'][0]['mode'], 'HASH');
        $this->assertEquals($tbl['indexes'][0]['key_block_size'], '4');
        $this->assertEquals($tbl['indexes'][0]['parser'], 'foo');
    }

    public function testForeignKeys(): void
    {
        # [CONSTRAINT [symbol]] FOREIGN KEY [index_name] (index_col_name,...) reference_definition
        #   reference_definition:
        #    REFERENCES tbl_name (index_col_name,...)
        #      [MATCH FULL | MATCH PARTIAL | MATCH SIMPLE]
        #      [ON DELETE reference_option]
        #      [ON UPDATE reference_option]

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FOREIGN KEY (bar) REFERENCES f_foo (f_bar) MATCH FULL ON DELETE SET NULL)');
        $this->assertEquals($tbl['indexes'][0]['type'], 'FOREIGN');
        $this->assertEquals($tbl['indexes'][0]['cols'], [['name' => 'bar']]);
        $this->assertEquals($tbl['indexes'][0]['ref_table'], 'f_foo');
        $this->assertEquals($tbl['indexes'][0]['ref_cols'], [['name' => 'f_bar']]);
        $this->assertEquals($tbl['indexes'][0]['ref_match'], 'FULL');
        $this->assertEquals($tbl['indexes'][0]['ref_on_delete'], 'SET NULL');

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, FOREIGN KEY bar (qux, `quux`) REFERENCES f_foo (f_bar))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'FOREIGN');
        $this->assertEquals($tbl['indexes'][0]['name'], 'bar');
        $this->assertEquals($tbl['indexes'][0]['cols'], [
            ['name' => 'qux'],
            ['name' => 'quux'],
        ]);
        $this->assertEquals($tbl['indexes'][0]['ref_table'], 'f_foo');
        $this->assertEquals($tbl['indexes'][0]['ref_cols'], [['name' => 'f_bar']]);
    }

    public function testChecks(): void
    {
        # CHECK (expr)

        # Extract expressions independently of the server's enforcement version.

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, CHECK (bar > 4))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'CHECK');
        $this->assertEquals(count($tbl['indexes'][0]['tokens']), 5);

        $tbl = $this->get_first_table('CREATE TABLE foo (bar INT, CHECK (bar > 2 AND (bar <> 5 OR bar=6)))');
        $this->assertEquals($tbl['indexes'][0]['type'], 'CHECK');
        $this->assertEquals(count($tbl['indexes'][0]['tokens']), 16);
    }

    #[DataProvider('foreignKeyActionOrders')]
    public function testQualifiedForeignKeyRetainsConstraintAndBothActions(string $actions): void
    {
        $table = $this->get_first_table("CREATE TABLE child (parent_id int, CONSTRAINT `parent``fk` FOREIGN KEY (parent_id) REFERENCES `a.b`.`parents` (id) {$actions})");
        $foreignKey = $table['indexes'][0];

        $this->assertSame('parent`fk', $foreignKey['constraint_name']);
        $this->assertSame('a.b', $foreignKey['ref_database']);
        $this->assertSame('parents', $foreignKey['ref_table']);
        $this->assertSame('CASCADE', $foreignKey['ref_on_delete']);
        $this->assertSame('RESTRICT', $foreignKey['ref_on_update']);
    }

    public static function foreignKeyActionOrders(): array
    {
        return [
            ['ON DELETE CASCADE ON UPDATE RESTRICT'],
            ['ON UPDATE RESTRICT ON DELETE CASCADE'],
        ];
    }

    public function testExpressionAndPrefixIndexKeysPreserveOrdering(): void
    {
        $table = $this->get_first_table("CREATE TABLE names (name varchar(255), KEY lookup (name(20) DESC, (concat(name, 'a  b')) ASC))");

        $this->assertSame([
            ['name' => 'name', 'length' => '20', 'direction' => 'DESC'],
            ['expression' => "(concat(name, 'a  b'))", 'direction' => 'ASC'],
        ], $table['indexes'][0]['cols']);
    }

    public function testTableAndColumnChecksRetainNamesExpressionsAndEnforcement(): void
    {
        $table = $this->get_first_table(<<<'SQL'
            CREATE TABLE accounts (
                balance int CONSTRAINT positive CHECK (balance >= 0),
                name varchar(255),
                CONSTRAINT `valid_name` CHECK (name <> 'a  b') /*!80016 NOT ENFORCED */,
                CONSTRAINT CHECK (balance < 1000) ENFORCED
            )
            SQL);

        $this->assertSame('positive', $table['indexes'][0]['constraint_name']);
        $this->assertSame('(balance >= 0)', $table['indexes'][0]['expression']);
        $this->assertTrue($table['indexes'][0]['enforced']);
        $this->assertSame('valid_name', $table['indexes'][1]['constraint_name']);
        $this->assertSame("(name <> 'a  b')", $table['indexes'][1]['expression']);
        $this->assertFalse($table['indexes'][1]['enforced']);
        $this->assertTrue($table['indexes'][2]['enforced']);
    }

    public function testInlineKeysAreIncludedInTableIndexes(): void
    {
        $table = $this->get_first_table('CREATE TABLE users (id int PRIMARY KEY, email varchar(255) UNIQUE)');

        $this->assertSame(['PRIMARY', 'UNIQUE'], array_column($table['indexes'], 'type'));
        $this->assertSame([['name' => 'id']], $table['indexes'][0]['cols']);
        $this->assertSame([['name' => 'email']], $table['indexes'][1]['cols']);
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
