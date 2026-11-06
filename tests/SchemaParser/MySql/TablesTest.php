<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\Tests\TestCase;

class TablesTest extends TestCase
{
    public function testGeneralCreation(): void
    {
        $table = $this->getFirstTable('CREATE TABLE foo');
        $this->assertSame('foo', $table['name']);

        // REMOVED: Temporary objects are not part of the persistent schema.
        $parser = new SqlParser;
        $parser->parse('CREATE TEMPORARY TABLE foo (other int)');
        $this->assertSame([], $parser->tables);

        $parser->parse('CREATE TABLE foo (id int); CREATE TEMPORARY TABLE foo (other int);');
        $this->assertCount(1, $parser->tables);
        $this->assertSame('id', array_values($parser->tables)[0]['fields'][0]['name']);
    }

    public function testIfNotExists(): void
    {
        $table = $this->getFirstTable('CREATE TABLE bar');
        $conditionalTable = $this->getFirstTable('CREATE TABLE IF NOT EXISTS bar');

        // The original SQL differs between the two declarations.
        unset($table['sql'], $conditionalTable['sql']);

        $this->assertSame($table, $conditionalTable);
    }

    public function testCreateTableLike(): void
    {
        $table = $this->getFirstTable('CREATE TABLE foo LIKE `bar`');

        $this->assertSame('foo', $table['name']);
        $this->assertSame('bar', $table['like']);
    }

    public function testCreateTableLikeWithDb(): void
    {
        $table = $this->getFirstTable('CREATE TABLE db.foo LIKE `db2`.`bar`');

        $this->assertSame('foo', $table['name']);
        $this->assertSame('db', $table['database']);
        $this->assertSame('bar', $table['like']);
        $this->assertSame('db2', $table['like_database']);
    }

    public function testFinalStatementRetainsItsSqlWithoutATerminator(): void
    {
        $sql = 'CREATE TABLE foo (id int)';

        $this->assertSame($sql, $this->getFirstTable($sql)['sql']);
    }

    public function testQuotedDotsAndUnspecifiedDatabasesHaveDistinctIdentities(): void
    {
        $parser = new SqlParser;
        $parser->parse('CREATE TABLE `a.b`.`c` (id int); CREATE TABLE `a`.`b.c` (id int); CREATE TABLE `a.b.c` (id int);');

        $this->assertCount(3, $parser->tables);
        $this->assertSame(['a.b', 'a', null], array_column($parser->tables, 'database'));
        $this->assertSame(['c', 'b.c', 'a.b.c'], array_column($parser->tables, 'name'));
    }

    public function testRoutineDelimitersDoNotExposeTablesInsideBodies(): void
    {
        $parser = new SqlParser;
        $parser->parse(<<<'SQL'
            DELIMITER ;;
            CREATE PROCEDURE first_routine()
            BEGIN
                SELECT 'a;;b';
                CREATE TABLE phantom (id int);
            END;;
            DELIMITER $$
            CREATE PROCEDURE second_routine()
            BEGIN
                /* DELIMITER ; and CREATE TABLE hidden (id int); */
                SELECT '$$';
                CREATE TABLE another_phantom (id int);
            END$$
            CREATE PROCEDURE third_routine()
            BEGIN
                CREATE TABLE third_phantom (id int);
            END
            $$
            DELIMITER ;
            CREATE TABLE real_table (id int);
            SQL);

        $this->assertSame(['real_table'], array_column($parser->tables, 'name'));
    }

    public function testDigitLeadingTableAndColumnNames(): void
    {
        $table = $this->getFirstTable('CREATE TABLE application.2fa_codes (2fa_code int DEFAULT 0x01, $label varchar(20))');

        $this->assertSame('application', $table['database']);
        $this->assertSame('2fa_codes', $table['name']);
        $this->assertSame('2fa_code', $table['fields'][0]['name']);
        $this->assertSame('0x01', $table['fields'][0]['default']);
        $this->assertSame('$label', $table['fields'][1]['name']);
    }

    public function testDatabaseSelectionDropAndConditionalCreation(): void
    {
        $parser = new SqlParser;
        $parser->parse(<<<'SQL'
            USE first_database;
            CREATE TABLE users (id int);
            CREATE TABLE IF NOT EXISTS users (other int);
            USE second_database;
            CREATE TABLE users (name varchar(255));
            DROP TABLE IF EXISTS first_database.users;
            SQL);

        $this->assertCount(1, $parser->tables);
        $table = array_values($parser->tables)[0];
        $this->assertSame('second_database', $table['database']);
        $this->assertSame('name', $table['fields'][0]['name']);

        $parser->parse('CREATE TABLE foo (id int); CREATE TABLE IF NOT EXISTS foo (other int)');
        $this->assertSame('id', array_values($parser->tables)[0]['fields'][0]['name']);
    }

    public function testExecutableViewFragmentsReplaceDumpStandIns(): void
    {
        $parser = new SqlParser;
        $parser->parse(<<<'SQL'
            CREATE TABLE report (value int);
            /*!50001 CREATE ALGORITHM=UNDEFINED */
            /*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
            /*!50001 VIEW `report` AS select 'a  b' AS `value` */;
            CREATE VIEW IF NOT EXISTS report AS SELECT 'replacement' AS value;
            CREATE VIEW IF NOT EXISTS added AS SELECT 'new view' AS value;
            CREATE VIEW removed AS SELECT 1;
            DROP VIEW IF EXISTS removed;
            SQL);

        $this->assertSame([], $parser->tables);
        $this->assertCount(2, $parser->views);
        $view = array_values($parser->views)[0];
        $this->assertSame('report', $view['name']);
        $this->assertSame("select 'a  b' AS `value`", $view['definition']);
        $this->assertSame("SELECT 'new view' AS value", array_values($parser->views)[1]['definition']);
    }

    /**
     * Parse the first table in a declaration.
     *
     * @return array<string, mixed>
     */
    private function getFirstTable(string $sql): array
    {
        $parser = new SqlParser;
        $parser->parse($sql);

        return array_values($parser->tables)[0];
    }
}
