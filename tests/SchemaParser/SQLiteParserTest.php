<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\Deferral;
use Hypervel\SchemaParser\ForeignKeyMatch;
use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\IndexColumn;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\ReferentialAction;
use Hypervel\SchemaParser\SQLiteParser;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SQLiteParserTest extends TestCase
{
    /**
     * @param list<string> $modifiers
     */
    #[DataProvider('typeProvider')]
    public function testTypesKeepTheirWordsAndModifiersInEitherDumpForm(string $definition, string $name, array $modifiers = []): void
    {
        $type = (new SQLiteParser)->parse("CREATE TABLE example (value {$definition})")->table('example')->column('value')->type;

        $this->assertSame($name, $type->name);
        $this->assertSame($modifiers, $type->modifiers);
    }

    /**
     * Provide declared types with their names and modifiers, as .schema and .schema --indent print them.
     *
     * @return iterable<string, array{0: string, 1: string, 2?: list<string>}>
     */
    public static function typeProvider(): iterable
    {
        yield 'spaced modifiers' => ['varchar   (255)', 'varchar', ['255']];
        yield 'indented modifiers' => ['varchar(255)', 'varchar', ['255']];
        yield 'spaced words' => ['UNSIGNED   BIG  INT', 'unsigned big int'];
        yield 'signed decimal modifiers' => ['numeric( 10.5 , -2 )', 'numeric', ['10.5', '-2']];
        yield 'quoted with a space' => ['"INTEGER "', 'integer '];
        yield 'single-quoted' => ["'TEXT'", 'text'];
        yield 'bracketed' => ['[big  int]', 'big  int'];
        yield 'keywords read as type words' => ['integer initially deferred', 'integer initially deferred'];
        yield 'generated as a type word' => ['generated', 'generated'];
        yield 'none' => ['', ''];
    }

    #[DataProvider('primaryKeyProvider')]
    public function testOnlyRowidAliasesAutoIncrementAndKeyNullabilityFollowsTheTable(string $definition, bool $autoIncrement, bool $nullable): void
    {
        $column = (new SQLiteParser)->parse("CREATE TABLE example {$definition}")->table('example')->columns[0];

        $this->assertSame($autoIncrement, $column->autoIncrement);
        $this->assertSame($nullable, $column->nullable);
    }

    /**
     * Provide table declarations whose first column is in the primary key.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function primaryKeyProvider(): iterable
    {
        yield 'rowid alias' => ['(id integer primary key)', true, false];
        yield 'rowid alias with AUTOINCREMENT' => ['(id INTEGER PRIMARY KEY ON CONFLICT FAIL AUTOINCREMENT)', true, false];
        yield 'quoted rowid alias' => ['(id "integer" primary key)', true, false];
        yield 'table key with DESC' => ['(id integer, primary key (id desc))', true, false];
        yield 'table key in another case' => ['(Id integer, primary key (id))', true, false];
        yield 'collated table key in brackets' => ['(id integer, primary key ((id collate nocase)))', true, false];
        yield 'NULL after the key' => ['(id integer primary key null)', true, false];
        yield 'column key with DESC' => ['(id integer primary key desc)', false, true];
        yield 'quoted type with a space' => ['(id "INTEGER " primary key)', false, true];
        yield 'integer with a modifier' => ['(id integer(10) primary key)', false, true];
        yield 'int' => ['(id int primary key)', false, true];
        yield 'composite key' => ['(id integer, other integer, primary key (id, other))', false, true];
        yield 'WITHOUT ROWID' => ['(id integer primary key, other) without rowid', false, false];
        yield 'STRICT' => ['(id text primary key, other int) strict', false, false];
        yield 'STRICT WITHOUT ROWID' => ['(id integer primary key) strict, without rowid', false, false];
    }

    public function testDefaultsAndGeneratedExpressionsMatchPragmaText(): void
    {
        $table = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE example (
                a int default ((1 + 2) * 3),
                b default ( (1) ),
                c default (/* note */ 1 ),
                d default - 1,
                e default 'it''s',
                f default null,
                g default CURRENT_TIMESTAMP,
                h default +2.5,
                i default x'0A',
                j int as (a * 2),
                k int generated always as (a + 1) stored,
                l as (a) virtual,
                m text
            )
            SQL)->table('example');

        $this->assertSame(
            ['(1 + 2) * 3', '(1)', '/* note */ 1', '- 1', "'it''s'", 'null', 'CURRENT_TIMESTAMP', '+2.5', "x'0A'", null, null, null, null],
            array_column($table->columns, 'default'),
        );
        $this->assertSame(GenerationType::Virtual, $table->column('j')->generation->type);
        $this->assertSame('(a * 2)', $table->column('j')->generation->expression);
        $this->assertSame(GenerationType::Stored, $table->column('k')->generation->type);
        $this->assertSame(GenerationType::Virtual, $table->column('l')->generation->type);
        $this->assertNull($table->column('m')->generation);
    }

    public function testIndexesForeignKeysChecksAndViewsShareTheCommonModel(): void
    {
        $schema = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE users (id integer primary key autoincrement not null);
            CREATE TABLE IF NOT EXISTS "posts" (
                id integer primary key autoincrement not null,
                "user id" integer references users(id) on delete cascade on update set null,
                title varchar not null,
                body text collate nocase,
                CONSTRAINT body_present CHECK (body <> ''),
                UNIQUE (title COLLATE nocase DESC) ON CONFLICT REPLACE,
                FOREIGN KEY (TITLE) REFERENCES titles (name) MATCH FULL
            );
            CREATE UNIQUE INDEX "posts_title_index" on "posts" ("title" DESC, lower(body), (title)) WHERE body IS NOT NULL;
            CREATE VIEW recent AS SELECT * FROM posts WHERE id > 10
            /* recent(id,"user id",title,body) */;
            SQL);
        $table = $schema->table('posts');

        $this->assertTrue($schema->isComplete());
        $this->assertSame('main', $table->schema);
        $this->assertSame('nocase', $table->column('body')->collation);
        $this->assertNull($table->primaryKey()->name);
        $this->assertSame(['id'], array_column($table->primaryKey()->columns, 'name'));
        $this->assertNull($table->indexes[1]->name);
        $this->assertTrue($table->indexes[1]->unique);
        $this->assertTrue($table->indexes[1]->constraint);
        $this->assertEquals([new IndexColumn('title', descending: true, collation: 'nocase')], $table->indexes[1]->columns);
        $index = $table->indexes[2];
        $this->assertSame('posts_title_index', $index->name);
        $this->assertTrue($index->unique);
        $this->assertFalse($index->constraint);
        $this->assertEquals(
            [new IndexColumn('title', descending: true), new IndexColumn(null, 'lower(body)'), new IndexColumn('title')],
            $index->columns,
        );
        $this->assertSame('body IS NOT NULL', $index->where);
        $this->assertSame(['user id'], $table->foreignKeys[0]->columns);
        $this->assertSame('main', $table->foreignKeys[0]->foreignTable->schema);
        $this->assertSame('users', $table->foreignKeys[0]->foreignTable->name);
        $this->assertSame(['id'], $table->foreignKeys[0]->foreignColumns);
        $this->assertSame(ReferentialAction::Cascade, $table->foreignKeys[0]->onDelete);
        $this->assertSame(ReferentialAction::SetNull, $table->foreignKeys[0]->onUpdate);
        $this->assertSame(['title'], $table->foreignKeys[1]->columns);
        $this->assertSame(ForeignKeyMatch::Full, $table->foreignKeys[1]->match);
        $this->assertSame('body_present', $table->checks[0]->name);
        $this->assertSame("(body <> '')", $table->checks[0]->expression);
        $this->assertSame('SELECT * FROM posts WHERE id > 10', $schema->view('recent')->definition);
    }

    /**
     * @param list<IndexColumn> $columns
     */
    #[DataProvider('indexKeyProvider')]
    public function testIndexKeysNameColumnsAsSQLiteResolvesThem(string $keys, array $columns): void
    {
        $table = (new SQLiteParser)->parse("CREATE TABLE example (Id INTEGER, \"true\" int, name text, desc int, asc int, like int); CREATE INDEX keys ON example {$keys};")
            ->table('example');

        $this->assertEquals($columns, $table->indexes[0]->columns);
    }

    /**
     * Provide index key lists with the keys SQLite's index_xinfo reports for them.
     *
     * @return iterable<string, array{string, list<IndexColumn>}>
     */
    public static function indexKeyProvider(): iterable
    {
        yield 'collated column in brackets' => ['((id COLLATE NOCASE) DESC)', [new IndexColumn('Id', descending: true, collation: 'NOCASE')]];
        yield 'outer collation' => ['((id COLLATE NOCASE) COLLATE RTRIM)', [new IndexColumn('Id', collation: 'RTRIM')]];
        yield 'nested brackets' => ['(((id)) COLLATE NOCASE, (name))', [new IndexColumn('Id', collation: 'NOCASE'), new IndexColumn('name')]];
        yield 'constants' => ["(NULL, FALSE, 'id')", [new IndexColumn(null, 'NULL'), new IndexColumn(null, 'FALSE'), new IndexColumn('Id')]];
        yield 'boolean column name' => ['(TRUE)', [new IndexColumn('true')]];
        yield 'double-quoted names' => ['("NAME", "nope")', [new IndexColumn('name'), new IndexColumn(null, '"nope"')]];
        yield 'bracketed expression' => ['((lower(name)))', [new IndexColumn(null, '(lower(name))')]];
        yield 'collation on a left operand' => ["(id COLLATE NOCASE || 'x')", [new IndexColumn(null, "id COLLATE NOCASE || 'x'")]];
        yield 'collation on a right operand' => ['(id + 1 COLLATE NOCASE)', [new IndexColumn(null, 'id + 1 COLLATE NOCASE')]];
        yield 'collation after NOT' => ['(NOT (id) COLLATE NOCASE)', [new IndexColumn(null, 'NOT (id) COLLATE NOCASE')]];
        yield 'collated group' => ['((id + 1) COLLATE NOCASE)', [new IndexColumn(null, '(id + 1)', collation: 'NOCASE')]];
        yield 'collated function call' => ['(lower(name) COLLATE NOCASE COLLATE RTRIM DESC)', [new IndexColumn(null, 'lower(name)', true, collation: 'RTRIM')]];
        yield 'collated signed operand' => ['(-id COLLATE NOCASE)', [new IndexColumn(null, '-id', collation: 'NOCASE')]];
        yield 'collation inside an expression wrapper' => ['(((id + 1) COLLATE NOCASE))', [new IndexColumn(null, '((id + 1) COLLATE NOCASE)')]];
        yield 'sort keyword inside CASE' => ['(CASE WHEN id THEN desc ELSE 0 END DESC)', [new IndexColumn(null, 'CASE WHEN id THEN desc ELSE 0 END', true)]];
        yield 'sort keyword after an operator' => ['(id + desc)', [new IndexColumn(null, 'id + desc')]];
        yield 'sort keyword operand before a direction' => ['(id || desc DESC)', [new IndexColumn(null, 'id || desc', true)]];
        yield 'sort keywords after keyword operators' => ['(id BETWEEN asc AND desc)', [new IndexColumn(null, 'id BETWEEN asc AND desc')]];
        yield 'sort keyword after LIKE' => ['(id LIKE desc)', [new IndexColumn(null, 'id LIKE desc')]];
        yield 'LIKE as a name before a direction' => ['(id + like DESC)', [new IndexColumn(null, 'id + like', true)]];
        yield 'sort keyword after NOT LIKE' => ['(id NOT LIKE desc)', [new IndexColumn(null, 'id NOT LIKE desc')]];
        yield 'prefix NOT before a direction' => ['(NOT like DESC)', [new IndexColumn(null, 'NOT like', true)]];
        yield 'function named operator before a direction' => ['(id + operator(id) DESC)', [new IndexColumn(null, 'id + operator(id)', true)]];
    }

    public function testDeferralClausesApplyToTheMostRecentForeignKey(): void
    {
        $schema = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE child (
                a REFERENCES p,
                b INT DEFERRABLE INITIALLY DEFERRED,
                c REFERENCES p NOT DEFERRABLE INITIALLY DEFERRED,
                d REFERENCES p DEFERRABLE,
                e INT CHECK (e > 0) DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (a) REFERENCES p DEFERRABLE INITIALLY IMMEDIATE
            );
            CREATE TABLE early (x INT DEFERRABLE, y REFERENCES p);
            SQL);

        $this->assertSame(
            [Deferral::Deferred, null, Deferral::Deferred, Deferral::Immediate],
            array_column($schema->table('child')->foreignKeys, 'deferral'),
        );
        $this->assertNull($schema->table('early')->foreignKeys[0]->deferral);
    }

    public function testConstraintNamesLastUntilTheirColumnOrCommaEnds(): void
    {
        $table = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE names (
                a INT CONSTRAINT c1 NOT NULL CHECK (a > 0) REFERENCES p (id),
                b INT CHECK (b > 0),
                c INT CONSTRAINT first CONSTRAINT second CHECK (c > 0),
                CONSTRAINT shared CHECK (a < 5) CHECK (a < 6),
                CHECK (b < 9),
                CONSTRAINT third CONSTRAINT fourth CHECK (c < 9),
                CONSTRAINT lonely
            )
            SQL)->table('names');

        $this->assertTrue($table->isComplete());
        $this->assertSame(['c1', null, 'second', 'shared', 'shared', null, 'fourth'], array_column($table->checks, 'name'));
        $this->assertSame('c1', $table->foreignKeys[0]->name);
    }

    public function testForeignKeysKeepRepresentableMatchModesAndIgnoreInsertActions(): void
    {
        $schema = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE Parent (Id INTEGER PRIMARY KEY);
            CREATE TABLE child (
                x REFERENCES PARENT MATCH funky ON INSERT CASCADE ON DELETE CASCADE,
                y REFERENCES parent MATCH "PARTIAL"
            );
            SQL);
        $table = $schema->table('child');

        $this->assertSame([ForeignKeyMatch::Simple, ForeignKeyMatch::Partial], array_column($table->foreignKeys, 'match'));
        $this->assertSame(ReferentialAction::Cascade, $table->foreignKeys[0]->onDelete);
        $this->assertSame(['Id'], $table->foreignKeys[0]->foreignColumns);
        $this->assertCount(1, $table->issues);
        $this->assertStringContainsString('[funky]', $table->issues[0]->message);
    }

    public function testNamesResolveWithinTheirDatabaseWithoutCase(): void
    {
        $schema = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE "double" (a);
            CREATE TABLE [bracket] (a);
            CREATE TABLE `tick` (a);
            CREATE TABLE 'single' ('quoted column' text);
            CREATE TABLE Users (Id INTEGER, name, PRIMARY KEY (id));
            CREATE INDEX by_id ON USERS (id);
            CREATE INDEX IF NOT EXISTS BY_ID ON users (name);
            CREATE INDEX main.by_name ON users (name);
            DROP INDEX BY_NAME;
            CREATE TABLE t (a);
            CREATE TABLE aux.t (a);
            CREATE TABLE aux.parent (id INTEGER PRIMARY KEY);
            CREATE TABLE child (parent_id REFERENCES parent);
            CREATE TABLE aux.only_aux (a);
            CREATE INDEX i ON t (a);
            CREATE INDEX aux.j ON t (a);
            CREATE TABLE MAIN.qualified (a);
            DROP TABLE IF EXISTS main.only_aux;
            DROP TABLE T;
            CREATE TABLE temp.skipped (a);
            CREATE TEMP TABLE also_skipped (a);
            CREATE TABLE "TEMP".skipped_too (a);
            SQL);

        $this->assertSame([], $schema->issues);
        $this->assertSame(['double', 'bracket', 'tick', 'single'], array_column(array_slice($schema->tables, 0, 4), 'name'));
        $this->assertSame('quoted column', $schema->table('single')->columns[0]->name);
        $this->assertTrue($schema->table('Users')->column('Id')->autoIncrement);
        $this->assertSame(['by_id'], array_column(array_slice($schema->table('Users')->indexes, 1), 'name'));
        $this->assertNull($schema->table('t', 'main'));
        $this->assertSame(['j'], array_column($schema->table('t', 'aux')->indexes, 'name'));
        $this->assertNotNull($schema->table('only_aux', 'aux'));
        $this->assertSame('main', $schema->table('qualified')->schema);
        $this->assertSame('main', $schema->table('child')->foreignKeys[0]->foreignTable->schema);
        $this->assertSame([], $schema->table('child')->foreignKeys[0]->foreignColumns);
        $this->assertCount(1, $schema->table('child')->issues);
        $this->assertNull($schema->table('skipped'));
        $this->assertNull($schema->table('also_skipped'));
        $this->assertNull($schema->table('skipped_too'));

        $attached = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE aux.u (a);
            CREATE TABLE extra.u (a);
            CREATE INDEX aux.solo ON u (a);
            CREATE INDEX aux.shared ON u (a);
            CREATE INDEX extra.shared ON u (a);
            DROP INDEX solo;
            DROP INDEX shared;
            SQL);

        $this->assertSame(['shared'], array_column($attached->table('u', 'aux')->indexes, 'name'));
        $this->assertSame(['shared'], array_column($attached->table('u', 'extra')->indexes, 'name'));
        $this->assertCount(1, $attached->issues);
        $this->assertStringContainsString('ambiguous', $attached->issues[0]->message);
    }

    public function testUnappliedAlterationsKeepTheSchemaHonest(): void
    {
        $schema = (new SQLiteParser)->parse(<<<'SQL'
            CREATE TABLE altered (a);
            CREATE TABLE kept (a);
            CREATE VIEW dropped AS SELECT 1;
            ALTER TABLE altered ADD COLUMN b;
            DROP VIEW dropped;
            DROP TRIGGER IF EXISTS anything;
            SQL);

        $this->assertSame([], $schema->issues);
        $this->assertCount(1, $schema->table('altered')->issues);
        $this->assertTrue($schema->table('kept')->isComplete());
        $this->assertNull($schema->view('dropped'));

        $this->assertCount(1, (new SQLiteParser)->parse('CREATE TABLE kept (a); ALTER TABLE kept RENAME TO renamed;')->issues);
        $this->assertCount(1, (new SQLiteParser)->parse('ALTER TABLE missing ADD COLUMN b;')->issues);
    }

    public function testIncompleteTablesDoNotHideOtherDeclarations(): void
    {
        $schema = (new SQLiteParser)->parse(<<<'SQL'
            CREATE VIRTUAL TABLE docs USING fts5(body)
            /* docs(body) */;
            CREATE TABLE copied AS SELECT 1 AS a;
            CREATE TABLE optioned (a) WITHOUT ROWID, LATER;
            CREATE TRIGGER audit AFTER INSERT ON optioned BEGIN
              INSERT INTO optioned VALUES ('x;y');
            END;
            CREATE TABLE complete (a);
            INSERT INTO "migrations" VALUES(1,'2014_10_12_000000_create_users_table',1);
            SQL);

        $this->assertSame([], $schema->issues);
        $this->assertSame([], $schema->table('docs')->columns);
        $this->assertCount(1, $schema->table('docs')->issues);
        $this->assertCount(1, $schema->table('copied')->issues);
        $this->assertCount(1, $schema->table('optioned')->issues);
        $this->assertTrue($schema->table('complete')->isComplete());
        $this->assertSame(['docs', 'copied', 'optioned', 'complete'], array_column($schema->tables, 'name'));
    }

    #[DataProvider('malformedDefinitionProvider')]
    public function testMalformedDefinitionsFail(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message . ' at SQL line 1');

        (new SQLiteParser)->parse($sql);
    }

    /**
     * Provide malformed SQLite declarations with their errors.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function malformedDefinitionProvider(): iterable
    {
        yield 'column INITIALLY' => ['CREATE TABLE broken (a REFERENCES p INITIALLY DEFERRED)', 'Expected DEFERRABLE before INITIALLY'];
        yield 'table INITIALLY' => ['CREATE TABLE broken (a, FOREIGN KEY (a) REFERENCES p INITIALLY DEFERRED)', 'Expected DEFERRABLE before INITIALLY'];
        yield 'initial mode' => ['CREATE TABLE broken (a REFERENCES p DEFERRABLE INITIALLY LATER)', 'Expected DEFERRED or IMMEDIATE'];
        yield 'named modifier' => ['CREATE TABLE broken (a varchar(max))', 'Expected a type modifier number'];
        yield 'third modifier' => ['CREATE TABLE broken (a numeric(1, 2, 3))', 'Expected the end of the type modifiers'];
        yield 'conflict resolution' => ['CREATE TABLE broken (a NOT NULL ON CONFLICT LATER)', 'Expected a conflict resolution'];
        yield 'default' => ['CREATE TABLE broken (a DEFAULT)', 'Expected a default expression'];
        yield 'referenced table' => ['CREATE TABLE broken (a, FOREIGN KEY (a) p)', 'Expected a referenced foreign table'];
        yield 'index table' => ['CREATE INDEX broken t (a)', 'Expected an index table'];
        yield 'index key' => ['CREATE TABLE t (a); CREATE INDEX broken ON t (a, )', 'Expected an index key'];
    }
}
