<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\CheckConstraint;
use Hypervel\SchemaParser\ForeignKey;
use Hypervel\SchemaParser\ForeignKeyMatch;
use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\IndexColumn;
use Hypervel\SchemaParser\MySqlParser;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\ReferentialAction;
use Hypervel\SchemaParser\Table;
use Hypervel\SchemaParser\TableReference;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MySqlParserTest extends TestCase
{
    public function testGlitchDatabaseDump(): void
    {
        $schema = (new MySqlParser)->parse(file_get_contents(__DIR__ . '/Fixtures/MySql/glitch_main.sql'));

        $this->assertTrue($schema->isComplete());
        $this->assertCount(115, $schema->tables);
        $this->assertSame(['share_code', 'service'], array_column($schema->table('achievements_share')->primaryKey()->columns, 'name'));
        $this->assertSame("'general'", $schema->table('help')->column('casetype')->default);
        $this->assertTrue($schema->table('users_votes')->column('id')->autoIncrement);
    }

    public function testColumnFactsAndRawDefaults(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE accounts (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                balance decimal(12, 2) NOT NULL DEFAULT -1,
                state enum('open', 'it''s closed') NOT NULL DEFAULT 'open',
                description varchar(255) DEFAULT NULL,
                plain varchar(255),
                literal_null varchar(255) NOT NULL DEFAULT 'NULL',
                updated_at timestamp(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) NOT NULL,
                label varchar(255) COLLATE `utf8mb4_bin` COMMENT 'user''s label' INVISIBLE,
                doubled decimal(12, 2) GENERATED ALWAYS AS (balance * 2) STORED COMMENT 'calculated',
                summary varchar(255) AS (concat('a , b', label)) VIRTUAL,
                position int COMMENT 'ordered' AUTO_INCREMENT UNIQUE VISIBLE
            ) ENGINE=InnoDB COLLATE=utf8mb4_bin COMMENT='account facts'
            SQL);

        $table = $schema->table('accounts');
        $this->assertTrue($schema->isComplete());
        $this->assertSame('InnoDB', $table->engine);
        $this->assertSame('utf8mb4_bin', $table->collation);
        $this->assertSame('account facts', $table->comment);
        $this->assertFalse($table->column('id')->nullable);
        $this->assertTrue($table->column('id')->autoIncrement);
        $this->assertTrue($table->column('id')->type->unsigned);
        $this->assertSame(['id'], array_column($table->primaryKey()->columns, 'name'));
        $this->assertSame('decimal', $table->column('balance')->type->name);
        $this->assertSame(['12', '2'], $table->column('balance')->type->modifiers);
        $this->assertSame('-1', $table->column('balance')->default);
        $this->assertSame(['open', "it's closed"], $table->column('state')->type->values);
        $this->assertSame("'open'", $table->column('state')->default);
        $this->assertSame('NULL', $table->column('description')->default);
        $this->assertNull($table->column('plain')->default);
        $this->assertSame("'NULL'", $table->column('literal_null')->default);
        $this->assertFalse($table->column('literal_null')->nullable);
        $this->assertSame(['6'], $table->column('updated_at')->type->modifiers);
        $this->assertSame('CURRENT_TIMESTAMP(6)', $table->column('updated_at')->onUpdate);
        $this->assertFalse($table->column('updated_at')->nullable);
        $this->assertSame('`utf8mb4_bin`', $table->column('label')->collation);
        $this->assertSame("user's label", $table->column('label')->comment);
        $this->assertTrue($table->column('label')->invisible);
        $this->assertSame(GenerationType::Stored, $table->column('doubled')->generation->type);
        $this->assertSame('(balance * 2)', $table->column('doubled')->generation->expression);
        $this->assertSame('calculated', $table->column('doubled')->comment);
        $this->assertSame(GenerationType::Virtual, $table->column('summary')->generation->type);
        $this->assertSame("(concat('a , b', label))", $table->column('summary')->generation->expression);
        $this->assertTrue($table->column('position')->autoIncrement);
        $this->assertFalse($table->column('position')->nullable);
        $this->assertSame('ordered', $table->column('position')->comment);
        $this->assertFalse($table->column('position')->invisible);
        $this->assertSame(['position'], array_column($table->indexes[1]->columns, 'name'));
        $this->assertTrue($table->indexes[1]->unique);
    }

    /**
     * @param list<string> $modifiers
     * @param list<string> $values
     */
    #[DataProvider('typeProvider')]
    public function testTypesKeepTheirNameModifiersAndAttributes(string $definition, string $name, array $modifiers, bool $unsigned = false, array $values = []): void
    {
        $schema = (new MySqlParser)->parse("CREATE TABLE example (value {$definition})");
        $type = $schema->table('example')->column('value')->type;

        $this->assertTrue($schema->isComplete());
        $this->assertSame([$name, $modifiers, $unsigned, $values], [$type->name, $type->modifiers, $type->unsigned, $type->values]);
    }

    /**
     * Provide type spellings with their modifiers, labels and attributes.
     *
     * @return iterable<string, array{0: string, 1: string, 2: list<string>, 3?: bool, 4?: list<string>}>
     */
    public static function typeProvider(): iterable
    {
        yield 'plain' => ['INT', 'int', []];
        yield 'spaced modifier' => ['smallint (4)', 'smallint', ['4']];
        yield 'unsigned' => ['MEDIUMINT UNSIGNED', 'mediumint', [], true];
        yield 'zero fill implies unsigned' => ['BIGINT(20) ZEROFILL', 'bigint', ['20'], true];
        yield 'precision and scale' => ['Float(3, 4) SIGNED', 'float', ['3', '4']];
        yield 'two-word name' => ['DOUBLE PRECISION(10,2) UNSIGNED', 'double precision', ['10', '2'], true];
        yield 'varying character' => ['CHARACTER VARYING(20)', 'character varying', ['20']];
        yield 'binary attribute and character set' => ['CHAR BINARY CHARACTER SET `utf19`', 'char', []];
        yield 'character set before collation' => ['VARCHAR(15) CHARSET utf8mb4 COLLATE utf8mb4_bin', 'varchar', ['15']];
        yield 'set labels' => ["SET('one', 'two') CHARACTER SET utf8mb4", 'set', [], false, ['one', 'two']];
        yield 'opaque modifiers' => ['CUSTOM_TYPE(10, 2)', 'custom_type', ['10', '2']];
    }

    public function testLabelsCommentsAndNamesAreDecoded(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (
                `a``b` ENUM('hel\'lo', "hel\"lo", "hel\'lo", 'hel\"lo', 'hel''lo', "hel""lo", "hel''lo", 'hel""lo',
                    'nul\0', 'backspace\b', 'control\Z', 'slash\\', 'percent\%', 'underscore\_', 'unknown\q')
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin COMMENT 'path\\name'
            )
            SQL);
        $column = $schema->table('example')->column('a`b');

        $this->assertSame(
            ["hel'lo", 'hel"lo', "hel'lo", 'hel"lo', "hel'lo", 'hel"lo', "hel''lo", 'hel""lo',
                "nul\0", "backspace\x08", "control\x1a", 'slash\\', 'percent\%', 'underscore\_', 'unknownq'],
            $column->type->values,
        );
        $this->assertSame('utf8mb4_bin', $column->collation);
        $this->assertSame('path\name', $column->comment);
    }

    #[DataProvider('defaultProvider')]
    public function testDefaultsKeepTheirSqlText(string $expression): void
    {
        $schema = (new MySqlParser)->parse("CREATE TABLE example (value varchar(255) NOT NULL DEFAULT {$expression} COMMENT 'value')");
        $column = $schema->table('example')->column('value');

        $this->assertTrue($schema->isComplete());
        $this->assertSame($expression, $column->default);
        $this->assertFalse($column->nullable);
        $this->assertSame('value', $column->comment);
    }

    /**
     * Provide literal, named and parenthesized default expressions.
     *
     * @return iterable<string, array{string}>
     */
    public static function defaultProvider(): iterable
    {
        yield 'string with spaces' => ["'a  b'"];
        yield 'bit literal' => ["b'101'"];
        yield 'hexadecimal literal' => ["X'0a1b'"];
        yield 'national string' => ["N'national'"];
        yield 'character set introducer' => ["_utf8mb4 'a  b'"];
        yield 'named expression with arguments' => ['CURRENT_TIMESTAMP(6)'];
        yield 'spaced sign' => ['- 1'];
        yield 'signed exponent' => ['+1e-5'];
        yield 'signed expression' => ['-(1 + 2)'];
        yield 'nested expression' => ["(concat('a, b', (1 + 2)))"];
    }

    public function testSerialExpandsToItsColumnAndUniqueKeyFacts(): void
    {
        $schema = (new MySqlParser)->parse('CREATE TABLE example (id SERIAL)');
        $table = $schema->table('example');
        $column = $table->column('id');

        $this->assertTrue($schema->isComplete());
        $this->assertSame('bigint', $column->type->name);
        $this->assertTrue($column->type->unsigned);
        $this->assertFalse($column->nullable);
        $this->assertTrue($column->autoIncrement);
        $this->assertTrue($table->indexes[0]->unique);
        $this->assertSame('id', $table->indexes[0]->columns[0]->name);
    }

    #[DataProvider('sqlModeDeclarations')]
    public function testStatementModesControlScanningAndDecoding(string $sql, string $columnName): void
    {
        $sql .= <<<'SQL'

            CREATE TABLE following (id int COMMENT 'line\nend') COMMENT='line\nend';
            SQL;
        $schema = (new MySqlParser)->parse($sql);

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['example', 'following'], array_column($schema->tables, 'name'));
        $this->assertSame(['C:\\', "it's"], $schema->table('example')->column($columnName)->type->values);
        $this->assertSame('C:\\', $schema->table('example')->column($columnName)->comment);
        $this->assertSame('C:\\', $schema->table('example')->comment);
        $this->assertSame('InnoDB', $schema->table('example')->engine);
        $this->assertSame("line\nend", $schema->table('following')->column('id')->comment);
        $this->assertSame("line\nend", $schema->table('following')->comment);
    }

    /**
     * Provide native mode transitions and declarations using their quoting rules.
     *
     * @return array<string, array{string, string}>
     */
    public static function sqlModeDeclarations(): array
    {
        return [
            'no backslash escapes' => [<<<'SQL'
                /*!50003 SET @saved_sql_mode = @@sql_mode */;
                /*!50003 SET sql_mode = 'NO_BACKSLASH_ESCAPES' */;
                DELIMITER ;;
                CREATE PROCEDURE example_routine()
                BEGIN
                    SET sql_mode = '';
                    SELECT 'C:\';
                    CREATE TABLE phantom (id int);
                END ;;
                DELIMITER ;
                CREATE TABLE example (labels enum('C:\', 'it''s') COMMENT 'C:\') ENGINE='InnoDB' COMMENT='C:\';
                /*!50003 SET sql_mode = @saved_sql_mode */;
                SQL, 'labels'],
            'ANSI identifier quotes' => [<<<'SQL'
                /*!50003 SET @@SESSION.sql_mode = 'ANSI_QUOTES' */;
                SET GLOBAL sql_mode = 'NO_BACKSLASH_ESCAPES', @@global.sql_mode = 'NO_BACKSLASH_ESCAPES';
                DELIMITER ;;
                CREATE PROCEDURE "quoted\"() SELECT 1 AS "alias\" ;;
                DELIMITER ;
                CREATE TABLE "example" ("labels\" enum('C:\\', 'it''s') COMMENT 'C:\\') ENGINE="InnoDB" COMMENT='C:\\';
                /*!50003 SET sql_mode = '' */;
                SQL, 'labels\\'],
            'unwrapped native save and restore' => [<<<'SQL'
                SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO,ANSI';
                SET @saved_sql_mode = @@sql_mode;
                SET sql_mode = 'NO_BACKSLASH_ESCAPES';
                DELIMITER ;;
                CREATE PROCEDURE example_routine() SELECT 'C:\' ;;
                DELIMITER ;
                SET sql_mode = @saved_sql_mode;
                CREATE TABLE "example" ("labels\" enum('C:\\', 'it''s') COMMENT 'C:\\') ENGINE="InnoDB" COMMENT='C:\\';
                SET SQL_MODE=@OLD_SQL_MODE;
                SQL, 'labels\\'],
        ];
    }

    public function testIndexesChecksAndForeignKeysShareTheCommonModel(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE app.parents (id int PRIMARY KEY);
            CREATE TABLE app.children (
                id int,
                parent_id int,
                name varchar(255),
                PRIMARY KEY (id),
                UNIQUE KEY by_name (name(20) DESC, id ASC),
                KEY folded_name ((concat(name, 'a  b')) ASC),
                CONSTRAINT parent_reference FOREIGN KEY (parent_id) REFERENCES app.parents (id)
                    MATCH SIMPLE ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT positive CHECK (id > 0) /*!80016 NOT ENFORCED */
            );
            CREATE UNIQUE INDEX parent_name ON app.children (parent_id, name);
            SQL);

        $table = $schema->table('children', 'app');
        $this->assertTrue($schema->isComplete());
        $this->assertSame('PRIMARY', $table->primaryKey()->name);
        $this->assertFalse($table->column('id')->nullable);
        $this->assertTrue($table->indexes[1]->unique);
        $this->assertTrue($table->indexes[1]->constraint);
        $this->assertEquals(
            [new IndexColumn('name', descending: true, length: 20), new IndexColumn('id')],
            $table->indexes[1]->columns,
        );
        $this->assertEquals([new IndexColumn(null, "(concat(name, 'a  b'))")], $table->indexes[2]->columns);
        $this->assertSame('parent_name', $table->indexes[3]->name);
        $this->assertTrue($table->indexes[3]->constraint);
        $this->assertSame('parent_reference', $table->foreignKeys[0]->name);
        $this->assertSame(['parent_id'], $table->foreignKeys[0]->columns);
        $this->assertSame('app', $table->foreignKeys[0]->foreignTable->schema);
        $this->assertSame(['id'], $table->foreignKeys[0]->foreignColumns);
        $this->assertSame(ReferentialAction::Restrict, $table->foreignKeys[0]->onUpdate);
        $this->assertSame(ReferentialAction::Cascade, $table->foreignKeys[0]->onDelete);
        $this->assertSame(ForeignKeyMatch::Simple, $table->foreignKeys[0]->match);
        $this->assertSame('positive', $table->checks[0]->name);
        $this->assertSame('(id > 0)', $table->checks[0]->expression);
        $this->assertFalse($table->checks[0]->enforced);
        $this->assertFalse($table->checks[0]->valid);
    }

    public function testPrimaryKeysFindTheirColumnsWithoutCase(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (Id int, `Σx` int, other int, PRIMARY KEY (id, `σx`));
            CREATE TABLE dotted (`İd` int, PRIMARY KEY (id));
            SQL);

        $this->assertSame([false, false, true], array_column($schema->table('example')->columns, 'nullable'));
        $this->assertFalse($schema->table('dotted')->column('İd')->nullable);
    }

    #[DataProvider('tableIndexProvider')]
    public function testTableIndexesKeepTheirNameKindAndMethod(string $declaration, ?string $name, ?string $type, bool $unique = false, bool $primary = false): void
    {
        $schema = (new MySqlParser)->parse("CREATE TABLE example (bar int NOT NULL, {$declaration})");
        $index = $schema->table('example')->indexes[0];

        $this->assertTrue($schema->isComplete());
        $this->assertSame([$name, $type, $unique, $primary, $unique], [$index->name, $index->type, $index->unique, $index->primary, $index->constraint]);
        $this->assertSame(['bar'], array_column($index->columns, 'name'));
    }

    /**
     * Provide index declarations with their names, kinds and methods.
     *
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string, 3?: bool, 4?: bool}>
     */
    public static function tableIndexProvider(): iterable
    {
        yield 'unnamed index' => ['INDEX(bar)', null, null];
        yield 'named key' => ['KEY baz (bar)', 'baz', null];
        yield 'method before keys' => ['INDEX  USING BTREE(bar)', null, 'btree'];
        yield 'unnamed unique' => ['UNIQUE(bar)', null, null, true];
        yield 'named unique index' => ['UNIQUE INDEX baz (bar)', 'baz', null, true];
        yield 'unique key' => ['UNIQUE KEY (bar)', null, null, true];
        yield 'unnamed constraint' => ['CONSTRAINT UNIQUE (bar)', null, null, true];
        yield 'constraint name' => ['CONSTRAINT `qux` UNIQUE INDEX(bar)', 'qux', null, true];
        yield 'index name over constraint name' => ['CONSTRAINT qux UNIQUE KEY baz (bar)', 'baz', null, true];
        yield 'primary key' => ['CONSTRAINT `qux` PRIMARY KEY(bar)', 'PRIMARY', null, true, true];
        yield 'primary method before keys' => ['PRIMARY KEY USING HASH (bar)', 'PRIMARY', 'hash', true, true];
        yield 'options' => ['PRIMARY KEY (bar) comment "hello world" USING HASH key_block_size=4 WITH PARSER foo INVISIBLE', 'PRIMARY', 'hash', true, true];
        yield 'unassigned option' => ['KEY (bar) KEY_BLOCK_SIZE 4 VISIBLE', null, null];
        yield 'fulltext' => ['FULLTEXT(bar)', null, 'fulltext'];
        yield 'fulltext index' => ['FULLTEXT INDEX(bar)', null, 'fulltext'];
        yield 'named fulltext key' => ['FULLTEXT KEY `baz` (bar)', 'baz', 'fulltext'];
        yield 'named spatial key' => ['spatial key by_location (bar)', 'by_location', 'spatial'];
    }

    public function testChecksKeepTheirNamesExpressionsAndEnforcement(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE accounts (
                balance int CONSTRAINT positive CHECK (balance >= 0),
                name varchar(255) CHECK (name <> ''),
                CONSTRAINT `valid_name` CHECK (name <> 'a  b') NOT ENFORCED,
                CONSTRAINT CHECK (balance < 1000) ENFORCED,
                CHECK (balance > 2 AND (balance <> 5 OR balance=6))
            )
            SQL);
        $checks = $schema->table('accounts')->checks;

        $this->assertTrue($schema->isComplete());
        $this->assertEquals([
            new CheckConstraint('positive', '(balance >= 0)'),
            new CheckConstraint(null, "(name <> '')"),
            new CheckConstraint('valid_name', "(name <> 'a  b')", enforced: false, valid: false),
            new CheckConstraint(null, '(balance < 1000)'),
            new CheckConstraint(null, '(balance > 2 AND (balance <> 5 OR balance=6))'),
        ], $checks);
    }

    #[DataProvider('foreignKeyActionOrders')]
    public function testForeignKeysKeepTheirRulesInEitherOrder(string $actions): void
    {
        $schema = (new MySqlParser)->parse("CREATE TABLE child (parent_id int, CONSTRAINT `parent``fk` FOREIGN KEY (parent_id) REFERENCES `a.b`.`parents` (id) {$actions})");
        $foreignKey = $schema->table('child')->foreignKeys[0];

        $this->assertSame('parent`fk', $foreignKey->name);
        $this->assertEquals(new TableReference('parents', 'a.b'), $foreignKey->foreignTable);
        $this->assertSame(ReferentialAction::Cascade, $foreignKey->onDelete);
        $this->assertSame(ReferentialAction::Restrict, $foreignKey->onUpdate);
    }

    /**
     * Provide both orders of a foreign key's referential actions.
     *
     * @return array<string, array{string}>
     */
    public static function foreignKeyActionOrders(): array
    {
        return [
            'delete first' => ['ON DELETE CASCADE ON UPDATE RESTRICT'],
            'update first' => ['ON UPDATE RESTRICT ON DELETE CASCADE'],
        ];
    }

    public function testInlineReferencesDeclareForeignKeysOnTheirColumn(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            USE app;
            CREATE TABLE parents (id int PRIMARY KEY);
            CREATE TABLE other.parents (id int PRIMARY KEY);
            CREATE TABLE children (
                parent_id int REFERENCES parents (id) ON DELETE CASCADE NOT NULL,
                other_id int REFERENCES other.parents (id) MATCH FULL ON UPDATE SET NULL COMMENT 'kept'
            );
            SQL);
        $table = $schema->table('children', 'app');

        // MySQL 8.4 parses and ignores these clauses.
        $this->assertSame(
            array_fill(0, 2, 'Inline REFERENCES creates a foreign key only on MySQL 9.0 and later.'),
            array_column($table->issues, 'message'),
        );
        $this->assertEquals([
            new ForeignKey(null, ['parent_id'], new TableReference('parents', 'app'), ['id'], ReferentialAction::NoAction, ReferentialAction::Cascade),
            new ForeignKey(null, ['other_id'], new TableReference('parents', 'other'), ['id'], ReferentialAction::SetNull, ReferentialAction::NoAction, ForeignKeyMatch::Full),
        ], $table->foreignKeys);
        $this->assertFalse($table->column('parent_id')->nullable);
        $this->assertSame('kept', $table->column('other_id')->comment);
    }

    public function testUnnamedForeignKeysLeaveTheirNameToTheServer(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE parents (id int PRIMARY KEY, code int, UNIQUE KEY (code));
            CREATE TABLE children (
                parent_id int,
                parent_code int,
                parent_other int,
                FOREIGN KEY (parent_id) REFERENCES parents (id) MATCH FULL ON DELETE SET NULL,
                FOREIGN KEY by_code (parent_code, `parent_other`) REFERENCES parents (code, id),
                CONSTRAINT FOREIGN KEY by_id (parent_id) REFERENCES parents (id)
            );
            SQL);
        $foreignKeys = $schema->table('children')->foreignKeys;

        $this->assertTrue($schema->isComplete());
        $this->assertSame([null, null, null], array_column($foreignKeys, 'name'));
        $this->assertSame(ForeignKeyMatch::Full, $foreignKeys[0]->match);
        $this->assertSame(ReferentialAction::SetNull, $foreignKeys[0]->onDelete);
        $this->assertSame(ReferentialAction::NoAction, $foreignKeys[0]->onUpdate);
        $this->assertSame(['parent_code', 'parent_other'], $foreignKeys[1]->columns);
        $this->assertSame(['code', 'id'], $foreignKeys[1]->foreignColumns);
    }

    public function testOmittedForeignColumnsResolveAgainstTheQualifiedPrimaryKey(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE app.children (parent_id int, FOREIGN KEY (parent_id) REFERENCES app.parents);
            CREATE TABLE other.parents (other_id int PRIMARY KEY);
            CREATE TABLE app.parents (id int PRIMARY KEY);
            SQL);

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['id'], $schema->table('children')->foreignKeys[0]->foreignColumns);
    }

    public function testConditionalAndReplacementIndexesPreserveTheirTableAndIdentity(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (first int, second int, KEY existing (first));
            CREATE INDEX IF NOT EXISTS existing ON example (second);
            CREATE INDEX IF NOT EXISTS added ON example (second);
            CREATE OR REPLACE UNIQUE INDEX ADDED ON example (first);
            CREATE OR REPLACE INDEX fresh ON example (second);
            CREATE TABLE other (id int, KEY added (id));
            SQL);
        $indexes = array_column($schema->table('example')->indexes, null, 'name');

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['existing', 'ADDED', 'fresh'], array_keys($indexes));
        $this->assertSame('first', $indexes['existing']->columns[0]->name);
        $this->assertTrue($indexes['ADDED']->unique);
        $this->assertSame('first', $indexes['ADDED']->columns[0]->name);
        $this->assertSame('second', $indexes['fresh']->columns[0]->name);
        $this->assertSame('id', $schema->table('other')->indexes[0]->columns[0]->name);
    }

    public function testDroppedIndexesAreMatchedByNameWithinTheirTable(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (id int PRIMARY KEY, first int, second int, KEY by_first (first), KEY by_second (second), KEY `Éx` (second));
            CREATE TABLE other (first int, KEY by_first (first));
            CREATE TABLE repeated (a int, KEY twice (a), KEY twice (a));
            DROP INDEX twice ON repeated;
            DROP INDEX BY_FIRST ON example;
            DROP INDEX `éx` ON example;
            DROP INDEX `PRIMARY` ON example;
            DROP INDEX IF EXISTS missing ON example;
            DROP INDEX by_first ON missing_table;
            SQL);

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['by_second'], array_column($schema->table('example')->indexes, 'name'));
        $this->assertSame(['by_first'], array_column($schema->table('other')->indexes, 'name'));
        // MySQL rejects repeated index names, but reading them must not fail.
        $this->assertSame(['twice'], array_column($schema->table('repeated')->indexes, 'name'));
    }

    public function testConditionalIndexesDoNotGuessServerGeneratedNames(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (id SERIAL, other_id int);
            CREATE INDEX IF NOT EXISTS id ON example (other_id);
            DROP INDEX id ON example;
            SQL);

        $this->assertFalse($schema->table('example')->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertCount(2, $schema->table('example')->issues);
        $this->assertCount(1, $schema->table('example')->indexes);
        $this->assertSame('id', $schema->table('example')->indexes[0]->columns[0]->name);
    }

    #[DataProvider('tableOptionProvider')]
    public function testTableOptionsAcceptTheirAlternativeSpellings(string $options, ?string $engine, ?string $collation = null, ?string $comment = null): void
    {
        $schema = (new MySqlParser)->parse('CREATE TABLE example (id int) ' . $options);
        $table = $schema->table('example');

        $this->assertTrue($schema->isComplete());
        $this->assertSame([$engine, $collation, $comment], [$table->engine, $table->collation, $table->comment]);
    }

    /**
     * Provide table option spellings with the engine, collation and comment they declare.
     *
     * @return iterable<string, array{0: string, 1: ?string, 2?: ?string, 3?: ?string}>
     */
    public static function tableOptionProvider(): iterable
    {
        yield 'assigned engine' => ['ENGINE = InnoDB', 'InnoDB'];
        yield 'unassigned engine' => ['ENGINE InnoDB', 'InnoDB'];
        yield 'default character set' => ['DEFAULT CHARACTER SET foo', null];
        yield 'assigned character set' => ['CHARACTER SET = foo', null];
        yield 'default charset' => ['DEFAULT CHARSET foo', null];
        yield 'charset' => ['CHARSET=foo', null];
        yield 'default collation' => ['DEFAULT COLLATE bar', null, 'bar'];
        yield 'assigned collation' => ['COLLATE = bar', null, 'bar'];
        yield 'directories' => ["DATA DIRECTORY = '/data' INDEX DIRECTORY '/index'", null];
        yield 'dump options' => ['ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC', 'InnoDB', 'utf8mb4_0900_ai_ci'];
        yield 'lower case with optional commas' => ["tablespace app storage DISK engine = NDB, default charset utf8mb4 `engine_option` = 'value' comment = 'a  b'", 'NDB', null, 'a  b'];
    }

    #[DataProvider('tableOptionTails')]
    public function testTableOptionsPreserveFactsOrReportUncertainty(string $options, bool $complete, ?string $comment): void
    {
        $schema = (new MySqlParser)->parse('CREATE TABLE example (id int) ' . $options);
        $table = $schema->table('example');

        $this->assertSame($complete, $table->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertNotNull($table->column('id'));
        $this->assertSame('InnoDB', $table->engine);
        $this->assertSame($comment, $table->comment);
    }

    /**
     * Provide physical options and tails with different completeness guarantees.
     */
    public static function tableOptionTails(): array
    {
        return [
            'tablespace before engine' => ["/*!50100 TABLESPACE `app` */ ENGINE=InnoDB COMMENT='important'", true, 'important'],
            'statistics before comment' => ["ENGINE=InnoDB STATS_PERSISTENT=0 COMMENT='important'", true, 'important'],
            'quoted engine options' => ["ENGINE=InnoDB `PAGE_COMPRESSED`='0' `ENCRYPTED`='NO' COMMENT='important'", true, 'important'],
            'unknown complex option' => ["ENGINE=InnoDB UNKNOWN_LAYOUT=(1, 2) COMMENT='important'", false, null],
            'physical partitions' => ["ENGINE=InnoDB COMMENT='important' PARTITION BY HASH(id) PARTITIONS 2", true, 'important'],
        ];
    }

    public function testNativeMySqlSpatialAndIndexOptionsRemainComplete(): void
    {
        $schema = (new MySqlParser)->parse(file_get_contents(__DIR__ . '/Fixtures/MySql/options.sql'));
        $table = $schema->table('places');
        $indexes = array_column($table->indexes, null, 'name');

        $this->assertTrue($schema->isComplete());
        $this->assertSame('important', $table->comment);
        $this->assertSame(['id', 'location', 'name'], array_column($table->columns, 'name'));
        $this->assertSame('point', $table->column('location')->type->name);
        $this->assertSame('spatial', $indexes['by_location']->type);
        $this->assertSame('name', $indexes['by_name']->columns[0]->name);
    }

    public function testPhysicalAttributesDoNotHideLaterColumnOrIndexOptions(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (
                location point SRID 4326 NOT NULL,
                notes blob COLUMN_FORMAT DYNAMIC STORAGE DISK DEFAULT NULL,
                id int ENGINE_ATTRIBUTE '{}' SECONDARY_ENGINE_ATTRIBUTE='{}' COMMENT 'kept',
                KEY by_id (id) ENGINE_ATTRIBUTE '{}' SECONDARY_ENGINE_ATTRIBUTE='{}' NOT IGNORED
            );
            SQL);

        $this->assertTrue($schema->isComplete());
        $table = $schema->table('example');
        $this->assertFalse($table->column('location')->nullable);
        $this->assertSame('NULL', $table->column('notes')->default);
        $this->assertSame('kept', $table->column('id')->comment);
        $this->assertSame('by_id', $table->indexes[0]->name);
    }

    public function testUnresolvedForeignColumnsStayExplicitlyIncomplete(): void
    {
        $schema = (new MySqlParser)->parse('CREATE TABLE children (parent_id int, FOREIGN KEY (parent_id) REFERENCES missing)');

        $this->assertFalse($schema->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertSame([], $schema->table('children')->foreignKeys[0]->foreignColumns);
        $this->assertCount(1, $schema->table('children')->issues);
    }

    public function testIncompleteTablesDoNotHideKnownColumnsOrOtherTables(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE known (id int);
            CREATE TABLE copied LIKE known;
            CREATE TABLE db.wrapped_copy (LIKE `db2`.`known`);
            CREATE TABLE selected (explicit_column int) AS SELECT id FROM known;
            CREATE TABLE inferred SELECT 1 AS id;
            CREATE TABLE bodiless;
            ALTER TABLE known ADD COLUMN added int;
            SQL);

        $this->assertFalse($schema->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertCount(6, $schema->tables);
        $this->assertNotNull($schema->table('known')->column('id'));
        $this->assertFalse($schema->table('known')->isComplete());
        $this->assertFalse($schema->table('copied')->isComplete());
        $this->assertFalse($schema->table('wrapped_copy', 'db')->isComplete());
        $this->assertNotNull($schema->table('selected')->column('explicit_column'));
        $this->assertFalse($schema->table('selected')->isComplete());
        $this->assertCount(1, $schema->table('inferred')->issues);
        $this->assertStringContainsString('CREATE TABLE SELECT', $schema->table('inferred')->issues[0]->message);
        $this->assertSame([], $schema->table('bodiless')->columns);
        $this->assertFalse($schema->table('bodiless')->isComplete());
    }

    public function testTableIdentitiesFollowDatabaseSelectionAndConditionalCreation(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE `a.b`.`c` (id int);
            CREATE TABLE `a`.`b.c` (id int);
            CREATE TABLE `a.b.c` (a INT);
            -- ignored comment

            CREATE TABLE DbName.TableName (b VARCHAR(10))

            ;
            USE first_database;
            CREATE TABLE users (id int);
            CREATE TABLE IF NOT EXISTS users (other int);
            CREATE TABLE sessions (id int);
            CREATE TEMPORARY TABLE sessions (other int);
            CREATE TEMPORARY TABLE scratch (id int);
            USE `second_database`;
            CREATE TABLE users (name varchar(255));
            CREATE TABLE IF NOT EXISTS second_database.users (other int);
            DROP TABLE IF EXISTS first_database.users, missing;
            CREATE TABLE application.2fa_codes (2fa_code int DEFAULT 0x01, $label varchar(20))
            SQL);

        $this->assertTrue($schema->isComplete());
        $this->assertSame(
            [['a.b', 'c'], ['a', 'b.c'], [null, 'a.b.c'], ['DbName', 'TableName'], ['first_database', 'sessions'], ['second_database', 'users'], ['application', '2fa_codes']],
            array_map(static fn (Table $table): array => [$table->schema, $table->name], $schema->tables),
        );
        $this->assertSame(['id'], array_column($schema->table('sessions', 'first_database')->columns, 'name'));
        $this->assertSame(['name'], array_column($schema->table('users', 'second_database')->columns, 'name'));
        $this->assertSame(['2fa_code', '$label'], array_column($schema->table('2fa_codes', 'application')->columns, 'name'));
        $this->assertSame('0x01', $schema->table('2fa_codes', 'application')->column('2fa_code')->default);
    }

    public function testRoutineBodiesDoNotDeclareTables(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
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

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['real_table'], array_column($schema->tables, 'name'));
    }

    public function testUnappliedRenamesInvalidateTheTableNameInventory(): void
    {
        $schema = (new MySqlParser)->parse("CREATE TABLE users (id int);\nALTER TABLE users RENAME TO accounts;");

        $this->assertFalse($schema->isComplete());
        $this->assertCount(1, $schema->issues);
        $this->assertSame(2, $schema->issues[0]->line);
    }

    public function testIssuesReportTheLineOfTheirStatement(): void
    {
        $parser = new MySqlParser;
        $schema = $parser->parse(<<<'SQL'
            CREATE TABLE bookings (
                starts_at date, ends_at date
            ) UNKNOWN_LAYOUT=(1, 2);
            -- keep original source positions
            ALTER TABLE bookings ADD COLUMN extra int;
            SQL);

        $this->assertSame([1, 5], array_column($schema->table('bookings')->issues, 'line'));
        $this->assertSame([2], array_column($parser->parse("\nALTER TABLE another ADD COLUMN extra int;")->issues, 'line'));
    }

    #[DataProvider('malformedDefinitionProvider')]
    public function testMalformedDefinitionsFail(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message . ' at SQL line 1');

        (new MySqlParser)->parse($sql);
    }

    /**
     * Provide truncated and malformed declarations with their errors.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function malformedDefinitionProvider(): iterable
    {
        yield 'database name' => ['USE;', 'Expected a database name'];
        yield 'table name' => ['CREATE TABLE', 'Expected a table name'];
        yield 'copied table name' => ['CREATE TABLE copied LIKE', 'Expected a copied table name'];
        yield 'unterminated body' => ['CREATE TABLE broken (id int, KEY by_id (id(10))', 'Unterminated table definition'];
        yield 'column type' => ['CREATE TABLE broken (id)', 'Expected a column type'];
        yield 'clause in place of a type' => ['CREATE TABLE broken (id NOT NULL)', 'Expected a column type'];
        yield 'empty entry' => ['CREATE TABLE broken (id int,, name int)', 'Expected a column name'];
        yield 'default' => ['CREATE TABLE broken (id int DEFAULT)', 'Expected a default expression'];
        yield 'signed default' => ['CREATE TABLE broken (id int DEFAULT -)', 'Expected a default expression'];
        yield 'label' => ['CREATE TABLE broken (state enum(1))', 'Expected an enum or set label'];
        yield 'character set' => ['CREATE TABLE broken (name char(10) CHARACTER SET)', 'Expected a value for CHARACTER SET'];
        yield 'collation' => ['CREATE TABLE broken (name char(10) COLLATE)', 'Expected a collation'];
        yield 'column comment' => ['CREATE TABLE broken (id int COMMENT)', 'Expected a column comment'];
        yield 'column format' => ['CREATE TABLE broken (id int COLUMN_FORMAT)', 'Expected a value for COLUMN_FORMAT'];
        yield 'column storage' => ['CREATE TABLE broken (id int STORAGE)', 'Expected a value for STORAGE'];
        yield 'spatial reference' => ['CREATE TABLE broken (location point SRID)', 'Expected a value for SRID'];
        yield 'assigned column option' => ['CREATE TABLE broken (location point REF_SYSTEM_ID=)', 'Expected a value for option REF_SYSTEM_ID'];
        yield 'column constraint name' => ['CREATE TABLE broken (id int CONSTRAINT)', 'Expected a constraint name'];
        yield 'table constraint name' => ['CREATE TABLE broken (id int, CONSTRAINT)', 'Expected a constraint name'];
        yield 'check expression' => ['CREATE TABLE broken (id int, CHECK)', 'Expected a parenthesized expression'];
        yield 'index keys' => ['CREATE TABLE broken (id int, INDEX lookup (id name))', 'Expected the end of the index key list'];
        yield 'prefix length' => ['CREATE TABLE broken (id int, KEY by_id (id(x)))', 'Expected an index prefix length'];
        yield 'index comment' => ['CREATE TABLE broken (id int, KEY by_id (id) COMMENT)', 'Expected an index comment'];
        yield 'index option' => ['CREATE TABLE broken (id int, KEY by_id (id) KEY_BLOCK_SIZE)', 'Expected a value for KEY_BLOCK_SIZE'];
        yield 'index parser' => ['CREATE TABLE broken (id int, KEY by_id (id) WITH PARSER)', 'Expected a parser name'];
        yield 'referenced table' => ['CREATE TABLE broken (id int, FOREIGN KEY (id) parent (id))', 'Expected a referenced foreign table'];
        yield 'missing action' => ['CREATE TABLE broken (id int, FOREIGN KEY (id) REFERENCES parent (id) ON DELETE)', 'Expected a referential action'];
        yield 'unknown action' => ['CREATE TABLE broken (id int, FOREIGN KEY (id) REFERENCES parent (id) ON UPDATE UNKNOWN)', 'Expected a referential action'];
        yield 'engine' => ['CREATE TABLE broken (id int) ENGINE=', 'Expected a value for ENGINE'];
        yield 'table comment' => ['CREATE TABLE broken (id int) COMMENT=', 'Expected a table comment'];
        yield 'assigned table option' => ['CREATE TABLE broken (id int) PHYSICAL_OPTION=', 'Expected a value for option PHYSICAL_OPTION'];
        yield 'indexed table' => ['CREATE INDEX lookup example (id)', 'Expected an index table'];
        yield 'dropped index table' => ['DROP INDEX lookup', 'Expected an index table'];
        yield 'view query' => ['CREATE VIEW broken SELECT 1', 'Expected a view query'];
    }

    #[DataProvider('viewCheckOptions')]
    public function testViewsReplaceStandInsAndKeepQueryText(string $checkOption): void
    {
        $sql = <<<'SQL'
            CREATE TABLE records (name varchar(255));
            CREATE TABLE report (name varchar(255));
            /*!50001 DROP TABLE IF EXISTS report*/;
            /*!50001 DROP VIEW IF EXISTS report*/;
            /*!50001 CREATE ALGORITHM=UNDEFINED */ /*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
            /*!50001 VIEW report AS select name from records where name <> 'a  b'
            SQL;
        $schema = (new MySqlParser)->parse($sql . $checkOption . " */;\n" . <<<'SQL'
            CREATE VIEW IF NOT EXISTS report AS SELECT 'replacement' AS name;
            CREATE VIEW IF NOT EXISTS added AS SELECT 'new view' AS value;
            ALTER ALGORITHM=MERGE DEFINER=CURRENT_USER() SQL SECURITY INVOKER VIEW added AS SELECT 'altered view' AS value;
            CREATE OR REPLACE VIEW removed AS SELECT 1;
            DROP VIEW IF EXISTS removed, missing;
            SQL);

        $this->assertTrue($schema->isComplete());
        $this->assertNull($schema->table('report'));
        $this->assertSame(['report', 'added'], array_column($schema->views, 'name'));
        $this->assertSame("select name from records where name <> 'a  b'", $schema->view('report')->definition);
        $this->assertSame("SELECT 'altered view' AS value", $schema->view('added')->definition);
    }

    /**
     * Provide view declaration suffixes that are not part of the query.
     */
    public static function viewCheckOptions(): array
    {
        return [[''], [' WITH CHECK OPTION'], [' WITH LOCAL CHECK OPTION'], [' WITH CASCADED CHECK OPTION']];
    }

    public function testParserReuseDoesNotRetainSuccessfulOrFailedInput(): void
    {
        $parser = new MySqlParser;
        $first = $parser->parse('CREATE TABLE first_table (id int)');

        try {
            $parser->parse("-- comment\nCREATE TABLE broken (`unterminated int)");
            $this->fail('Malformed identifiers must fail parsing.');
        } catch (ParseException $exception) {
            $this->assertSame(2, $exception->sqlLine);
        }

        $second = $parser->parse('CREATE TABLE second_table (id int)');
        $this->assertSame(['first_table'], array_column($first->tables, 'name'));
        $this->assertSame(['second_table'], array_column($second->tables, 'name'));
        $this->assertTrue($second->isComplete());
    }
}
