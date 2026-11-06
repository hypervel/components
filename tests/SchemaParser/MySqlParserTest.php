<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\ForeignKeyMatch;
use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\MySqlParser;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\ReferentialAction;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MySqlParserTest extends TestCase
{
    public function testUpstreamDatabaseDump(): void
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
                updated_at timestamp(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                label varchar(255) COLLATE `utf8mb4_bin` COMMENT 'user''s label' INVISIBLE,
                doubled decimal(12, 2) GENERATED ALWAYS AS (balance * 2) STORED
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
        $this->assertSame('`utf8mb4_bin`', $table->column('label')->collation);
        $this->assertSame("user's label", $table->column('label')->comment);
        $this->assertTrue($table->column('label')->invisible);
        $this->assertSame(GenerationType::Stored, $table->column('doubled')->generation->type);
        $this->assertSame('(balance * 2)', $table->column('doubled')->generation->expression);
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
                /*!50003 SET sql_mode = 'ANSI_QUOTES' */;
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
                UNIQUE KEY by_name (name(20) DESC),
                KEY folded_name ((lower(name))),
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
        $this->assertTrue($table->indexes[1]->columns[0]->descending);
        $this->assertSame(20, $table->indexes[1]->columns[0]->length);
        $this->assertNull($table->indexes[2]->columns[0]->name);
        $this->assertSame('(lower(name))', $table->indexes[2]->columns[0]->expression);
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

    public function testConditionalIndexesDoNotGuessServerGeneratedNames(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (id SERIAL, other_id int);
            CREATE INDEX IF NOT EXISTS id ON example (other_id);
            SQL);

        $this->assertFalse($schema->table('example')->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertCount(1, $schema->table('example')->issues);
        $this->assertCount(1, $schema->table('example')->indexes);
        $this->assertSame('id', $schema->table('example')->indexes[0]->columns[0]->name);
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
        $schema = (new MySqlParser)->parse(file_get_contents(__DIR__ . '/Fixtures/MySql/mysql-options.sql'));
        $table = $schema->table('places');
        $indexes = array_column($table->indexes, null, 'name');

        $this->assertTrue($schema->isComplete());
        $this->assertSame('important', $table->comment);
        $this->assertSame(['id', 'location', 'name'], array_column($table->columns, 'name'));
        $this->assertSame('point', $table->column('location')->type->name);
        $this->assertSame('spatial', $indexes['by_location']->type);
        $this->assertSame('name', $indexes['by_name']->columns[0]->name);
    }

    public function testNativeMariaDbTemporalDefinitionsRemainExplicitlyIncomplete(): void
    {
        $sql = file_get_contents(__DIR__ . '/Fixtures/MySql/mariadb-options.sql')
            . file_get_contents(__DIR__ . '/Fixtures/MySql/mariadb-periods.sql')
            . 'CREATE TABLE implicit_versioning (id int) WITH SYSTEM VERSIONING;';
        $schema = (new MySqlParser)->parse($sql);

        $this->assertFalse($schema->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertTrue($schema->table('places')->isComplete());
        $this->assertSame('important', $schema->table('places')->comment);
        $this->assertSame(['id', 'starts_at', 'ends_at'], array_column($schema->table('bookings')->columns, 'name'));
        $this->assertFalse($schema->table('bookings')->isComplete());
        $versioned = $schema->table('versioned');
        $this->assertSame(['id', 'row_start', 'row_end'], array_column($versioned->columns, 'name'));
        $this->assertNull($versioned->column('row_start')->generation);
        $this->assertCount(1, $versioned->issues);
        $this->assertFalse($schema->table('implicit_versioning')->isComplete());

        $rooms = $schema->table('rooms');
        $this->assertFalse($rooms->isComplete());
        $this->assertSame('NULL', $rooms->column('notes')->default);
        $this->assertTrue($rooms->indexes[0]->unique);
        $this->assertSame('id', $rooms->indexes[0]->columns[0]->name);
        $this->assertNull($rooms->indexes[0]->columns[1]->name);
        $this->assertSame('`stay` WITHOUT OVERLAPS', $rooms->indexes[0]->columns[1]->expression);
    }

    public function testVectorKeysDoNotChangeColumnsNamedVector(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE embeddings (
                embedding vector(3) NOT NULL,
                VECTOR KEY by_embedding (embedding) `m`='4' `distance`='cosine'
            );
            CREATE TABLE vector_names (vector VECTOR(3));
            SQL);

        $this->assertTrue($schema->isComplete());
        $this->assertSame(['embedding'], array_column($schema->table('embeddings')->columns, 'name'));
        $index = $schema->table('embeddings')->indexes[0];
        $this->assertSame('vector', $index->type);
        $this->assertSame('embedding', $index->columns[0]->name);
        $this->assertFalse($index->unique);
        $this->assertSame('vector', $schema->table('vector_names')->column('vector')->type->name);
        $this->assertSame([], $schema->table('vector_names')->indexes);
    }

    public function testPhysicalAttributesDoNotHideLaterColumnOrIndexOptions(): void
    {
        $schema = (new MySqlParser)->parse(<<<'SQL'
            CREATE TABLE example (
                location point REF_SYSTEM_ID=4326 NOT NULL,
                notes blob COMPRESSED=zlib DEFAULT NULL,
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

    public function testInvalidForeignKeyActionsFailBeforeMappingTheEnum(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Expected a referential action after ON DELETE');

        (new MySqlParser)->parse('CREATE TABLE child (id int, FOREIGN KEY (id) REFERENCES parent (id) ON DELETE UNKNOWN)');
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
            CREATE TABLE selected (explicit_column int) AS SELECT id FROM known;
            CREATE TABLE inferred SELECT 1 AS id;
            ALTER TABLE known ADD COLUMN added int;
            SQL);

        $this->assertFalse($schema->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertCount(4, $schema->tables);
        $this->assertNotNull($schema->table('known')->column('id'));
        $this->assertFalse($schema->table('known')->isComplete());
        $this->assertFalse($schema->table('copied')->isComplete());
        $this->assertNotNull($schema->table('selected')->column('explicit_column'));
        $this->assertFalse($schema->table('selected')->isComplete());
        $this->assertCount(1, $schema->table('inferred')->issues);
        $this->assertStringContainsString('CREATE TABLE SELECT', $schema->table('inferred')->issues[0]->message);
    }

    public function testUnappliedRenamesInvalidateTheTableNameInventory(): void
    {
        $schema = (new MySqlParser)->parse("CREATE TABLE users (id int);\nALTER TABLE users RENAME TO accounts;");

        $this->assertFalse($schema->isComplete());
        $this->assertCount(1, $schema->issues);
        $this->assertSame(2, $schema->issues[0]->line);
    }

    #[DataProvider('viewCheckOptions')]
    public function testViewsReplaceStandInsAndKeepQueryText(string $checkOption): void
    {
        $sql = <<<'SQL'
            CREATE TABLE records (name varchar(255));
            CREATE TABLE report (name varchar(255));
            /*!50001 CREATE ALGORITHM=UNDEFINED */ /*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
            /*!50001 VIEW report AS select name from records where name <> 'a  b'
            SQL;
        $schema = (new MySqlParser)->parse($sql . $checkOption . ' */;');

        $this->assertTrue($schema->isComplete());
        $this->assertNull($schema->table('report'));
        $this->assertSame("select name from records where name <> 'a  b'", $schema->view('report')->definition);
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
