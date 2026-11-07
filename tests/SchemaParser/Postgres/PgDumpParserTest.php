<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Postgres;

use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\Identity;
use Hypervel\SchemaParser\PostgresParser;
use Hypervel\Tests\SchemaParser\Fixtures\Postgres\AssertsSchema;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function sprintf;

class PgDumpParserTest extends TestCase
{
    use AssertsSchema;

    private PostgresParser $parser;

    /**
     * Create the reader used by each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PostgresParser;
    }

    #[Test]
    public function itReadsATableWithItsSchema(): void
    {
        $database = $this->parser->parse('CREATE TABLE reporting.summaries (id bigint NOT NULL);');

        $table = self::assertTable($database, 'summaries');

        $this->assertSame('reporting', $table->schema);
        $this->assertSame('summaries', $table->name);
    }

    #[Test]
    public function itPreservesAnUnspecifiedSchema(): void
    {
        $database = $this->parser->parse('CREATE TABLE users (id bigint);');

        $this->assertNull(self::assertTable($database, 'users')->schema);
    }

    #[Test]
    public function itReadsNullabilityFromNotNull(): void
    {
        $database = $this->parser->parse(
            'CREATE TABLE public.users (id bigint NOT NULL, nickname text);',
        );

        $this->assertFalse(self::assertColumn($database, 'users', 'id')->nullable);
        $this->assertTrue(self::assertColumn($database, 'users', 'nickname')->nullable);
    }

    #[Test]
    #[DataProvider('canonicalTypeProvider')]
    public function itCanonicalisesTypeNames(string $declared, string $expected): void
    {
        $database = $this->parser->parse(sprintf('CREATE TABLE t (c %s);', $declared));

        $type = self::assertColumnType($database, 't', 'c');
        $parts = explode('.', $expected);
        $this->assertSame(array_pop($parts), $type->name);
        $this->assertSame($parts[0] ?? null, $type->schema);
    }

    /**
     * pg_dump writes the SQL standard spellings in full. PostgreSQL resolves
     * them to its own names, and so does this parser, so a consumer only has to
     * know one name per type.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function canonicalTypeProvider(): iterable
    {
        yield 'integer' => ['integer', 'pg_catalog.int4'];
        yield 'bigint' => ['bigint', 'pg_catalog.int8'];
        yield 'smallint' => ['smallint', 'pg_catalog.int2'];
        yield 'boolean' => ['boolean', 'pg_catalog.bool'];
        yield 'double precision' => ['double precision', 'pg_catalog.float8'];
        yield 'real' => ['real', 'pg_catalog.float4'];
        yield 'character varying' => ['character varying(255)', 'pg_catalog.varchar'];
        yield 'character' => ['character(2)', 'pg_catalog.bpchar'];
        yield 'timestamp without time zone' => ['timestamp(0) without time zone', 'pg_catalog.timestamp'];
        yield 'timestamp with time zone' => ['timestamp with time zone', 'pg_catalog.timestamptz'];
        yield 'time with time zone' => ['time with time zone', 'pg_catalog.timetz'];
        yield 'bit varying' => ['bit varying(5)', 'pg_catalog.varbit'];
        yield 'interval' => ['interval day to second(3)', 'pg_catalog.interval'];
        yield 'uuid' => ['uuid', 'uuid'];
        yield 'jsonb' => ['jsonb', 'jsonb'];
    }

    #[Test]
    public function itKeepsTypeModifiers(): void
    {
        $database = $this->parser->parse('CREATE TABLE t (c numeric(10,2));');

        $this->assertSame(['10', '2'], self::assertColumnType($database, 't', 'c')->modifiers);
    }

    #[Test]
    public function itRecordsArrayDimensionsSeparatelyFromModifiers(): void
    {
        $database = $this->parser->parse('CREATE TABLE t (c character varying(50)[], d integer[][]);');

        $c = self::assertColumnType($database, 't', 'c');

        $this->assertSame('pg_catalog', $c->schema);
        $this->assertSame('varchar', $c->name);
        $this->assertSame(['50'], $c->modifiers);
        $this->assertSame(1, $c->dimensions);
        $this->assertTrue($c->isArray());

        $this->assertSame(2, self::assertColumnType($database, 't', 'd')->dimensions);
    }

    #[Test]
    public function itKeepsIntervalFieldsWithTheirPrecision(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE DOMAIN public.span AS INTERVAL DAY TO SECOND(3);
            CREATE TABLE t (a interval year to month, b interval(2), c interval minute[], d public.span, e public.span[]);
            SQL);

        $a = self::assertColumnType($database, 't', 'a');
        $this->assertSame('year to month', $a->intervalFields);
        $this->assertSame([], $a->modifiers);

        $b = self::assertColumnType($database, 't', 'b');
        $this->assertNull($b->intervalFields);
        $this->assertSame(['2'], $b->modifiers);

        $c = self::assertColumnType($database, 't', 'c');
        $this->assertSame('minute', $c->intervalFields);
        $this->assertSame(1, $c->dimensions);

        foreach (['d' => 0, 'e' => 1] as $column => $dimensions) {
            $type = self::assertColumnType($database, 't', $column);
            $this->assertSame('span', $type->name);
            $this->assertSame($dimensions, $type->dimensions);
            $this->assertSame('interval', $type->base?->name);
            $this->assertSame('day to second', $type->base?->intervalFields);
            $this->assertSame(['3'], $type->base?->modifiers);
        }
    }

    #[Test]
    public function itReadsEnumTypesAndLinksThemToColumns(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE TYPE public.mood AS ENUM ('sad', 'ok', 'happy');
            CREATE TABLE public.people (current public.mood);
            SQL);

        $type = self::assertColumnType($database, 'people', 'current');

        $this->assertSame('public', $type->schema);
        $enum = $database->enum($type->name, $type->schema);

        $this->assertNotNull($enum);
        $this->assertSame(['sad', 'ok', 'happy'], $enum->values);
    }

    #[Test]
    public function itResolvesADomainToTheTypeItWraps(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE DOMAIN public.positive_int AS integer CHECK (VALUE > 0);
            CREATE TABLE public.t (c public.positive_int);
            SQL);

        $declared = self::assertColumnType($database, 't', 'c');

        $this->assertSame('public', $declared->schema);
        $this->assertSame('positive_int', $declared->name);
        $this->assertSame('pg_catalog', $declared->base->schema);
        $this->assertSame('int4', $declared->base->name);
    }

    #[Test]
    public function itLeavesAnUnknownTypeAlone(): void
    {
        // Extension types are declared by the extension, not the dump, so there
        // is nothing to resolve them to.
        $database = $this->parser->parse('CREATE TABLE t (c public.hstore);');

        $type = self::assertColumnType($database, 't', 'c');

        $this->assertSame('public', $type->schema);
        $this->assertSame('hstore', $type->name);
        $this->assertNull($type->base);
        $this->assertTrue($database->isComplete());
    }

    #[Test]
    public function itReadsAPrimaryKeyAddedByALaterStatement(): void
    {
        // This is how pg_dump writes primary keys; they are not inline.
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.users (id bigint NOT NULL, email text NOT NULL);
            ALTER TABLE ONLY public.users ADD CONSTRAINT users_pkey PRIMARY KEY (id);
            SQL);

        $this->assertSame(['id'], array_column(self::assertTable($database, 'users')->primaryKey()->columns, 'name'));
    }

    #[Test]
    public function itReadsAnInlinePrimaryKey(): void
    {
        $database = $this->parser->parse('CREATE TABLE t (id bigint PRIMARY KEY, other text);');

        $this->assertSame(['id'], array_column(self::assertTable($database, 't')->primaryKey()->columns, 'name'));
    }

    #[Test]
    public function itReadsACompositePrimaryKey(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.pivot (a bigint NOT NULL, b bigint NOT NULL);
            ALTER TABLE ONLY public.pivot ADD CONSTRAINT pivot_pkey PRIMARY KEY (a, b);
            SQL);

        $this->assertSame(['a', 'b'], array_column(self::assertTable($database, 'pivot')->primaryKey()->columns, 'name'));
    }

    #[Test]
    public function itRecognisesASerialColumnByItsSequenceDefault(): void
    {
        // pg_dump rewrites `bigserial` into a bigint column plus a sequence, so
        // the default is the only remaining evidence.
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.users (id bigint NOT NULL);
            CREATE SEQUENCE public.users_id_seq AS integer START WITH 1;
            ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);
            SQL);

        $column = self::assertColumn($database, 'users', 'id');

        $this->assertTrue($column->autoIncrement);
        $this->assertSame("nextval('public.users_id_seq'::regclass)", $column->default);
    }

    #[Test]
    public function itTreatsOnlyAnUnqualifiedSerialNameAsSerial(): void
    {
        // PostgreSQL compares the folded type name to the serial names exactly.
        $database = $this->parser->parse('CREATE TABLE t (a SERIAL8, b "serial", c "SERIAL", d public.serial);');

        foreach (['a' => 'int8', 'b' => 'int4'] as $name => $type) {
            $column = self::assertColumn($database, 't', $name);
            $this->assertSame($type, $column->type->name);
            $this->assertSame('pg_catalog', $column->type->schema);
            $this->assertTrue($column->autoIncrement);
            $this->assertFalse($column->nullable);
        }

        foreach (['c' => [null, 'SERIAL'], 'd' => ['public', 'serial']] as $name => [$schema, $type]) {
            $column = self::assertColumn($database, 't', $name);
            $this->assertSame($type, $column->type->name);
            $this->assertSame($schema, $column->type->schema);
            $this->assertFalse($column->autoIncrement);
            $this->assertTrue($column->nullable);
        }
    }

    #[Test]
    public function itRecognisesIdentityAndGeneratedColumns(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.t (
                id integer NOT NULL GENERATED BY DEFAULT AS IDENTITY,
                doubled integer GENERATED ALWAYS AS ((id * 2)) STORED
            );
            SQL);

        $id = self::assertColumn($database, 't', 'id');
        $doubled = self::assertColumn($database, 't', 'doubled');

        $this->assertSame(Identity::ByDefault, $id->identity);
        $this->assertNull($id->generation);
        $this->assertSame(GenerationType::Stored, $doubled->generation->type);
        $this->assertSame('((id * 2))', $doubled->generation->expression);
        $this->assertNull($doubled->identity);
    }

    #[Test]
    public function itReadsAColumnDefault(): void
    {
        $database = $this->parser->parse(
            "CREATE TABLE t (active boolean DEFAULT false NOT NULL, label text DEFAULT 'x');",
        );

        $active = self::assertColumn($database, 't', 'active');

        $this->assertSame('false', $active->default);
        $this->assertFalse($active->nullable);
        $this->assertSame("'x'", self::assertColumn($database, 't', 'label')->default);
    }

    #[Test]
    public function itKeepsConstraintsSeparateFromColumns(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.t (
                id bigint NOT NULL,
                other_id bigint,
                CONSTRAINT t_check CHECK ((id > 0)),
                CONSTRAINT t_other_fk FOREIGN KEY (other_id) REFERENCES public.other(id)
            );
            SQL);

        $this->assertSame(
            ['id', 'other_id'],
            array_map(
                static fn ($column): string => $column->name,
                self::assertTable($database, 't')->columns,
            ),
        );
        $this->assertSame('t_check', $database->table('t')->checks[0]->name);
        $this->assertSame('t_other_fk', $database->table('t')->foreignKeys[0]->name);
    }

    #[Test]
    public function itKeepsOtherObjectsSeparateFromTables(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE SCHEMA reporting;
            CREATE EXTENSION IF NOT EXISTS hstore WITH SCHEMA public;
            CREATE TABLE public.users (id bigint);
            CREATE VIEW public.active AS SELECT id FROM public.users;
            CREATE INDEX users_id_idx ON public.users USING btree (id);
            COMMENT ON TABLE public.users IS 'people';
            SQL);

        $this->assertCount(1, $database->tables);
        $this->assertNotNull($database->table('users'));
        $this->assertSame('SELECT id FROM public.users', $database->view('active')->definition);
        $this->assertSame('users_id_idx', $database->table('users')->indexes[0]->name);
        $this->assertSame('people', $database->table('users')->comment);
        $this->assertTrue($database->isComplete());
    }

    #[Test]
    public function itIsNotConfusedByADollarQuotedFunctionBody(): void
    {
        // The body is arbitrary text containing semicolons, parentheses and even
        // something that looks like a CREATE TABLE.
        $database = $this->parser->parse(<<<'SQL'
            CREATE FUNCTION public.f() RETURNS trigger AS $$
            BEGIN
                -- CREATE TABLE public.decoy (id integer);
                NEW.updated_at = now();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TABLE public.real_table (id bigint);
            SQL);

        $this->assertCount(1, $database->tables);
        $this->assertNotNull($database->table('real_table'));
        $this->assertNull($database->table('decoy'));
    }

    #[Test]
    public function itIgnoresPsqlMetaCommands(): void
    {
        // pg_dump has wrapped its output in \restrict since PostgreSQL 18.
        $database = $this->parser->parse(<<<'SQL'
            \restrict aBcDeF123
            CREATE TABLE public.users (id bigint);
            \unrestrict aBcDeF123
            SQL);

        $this->assertCount(1, $database->tables);
    }

    #[Test]
    public function itDoesNotTreatACommentMarkerInsideALiteralAsAComment(): void
    {
        $database = $this->parser->parse(
            "CREATE TABLE t (a text DEFAULT '-- not a comment', b integer);",
        );

        $this->assertCount(2, self::assertTable($database, 't')->columns);
    }

    #[Test]
    public function itKeepsTheLastDefinitionOfARepeatedTable(): void
    {
        // Replaying a dump that drops and recreates leaves the last one standing.
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.users (id bigint);
            DROP TABLE IF EXISTS public.users;
            CREATE TABLE public.users (id bigint, email text);
            SQL);

        $this->assertCount(1, $database->tables);
        $this->assertCount(2, self::assertTable($database, 'users')->columns);
    }

    #[Test]
    public function itKeepsTablesOfTheSameNameInDifferentSchemasApart(): void
    {
        $database = $this->parser->parse(<<<'SQL'
            CREATE TABLE public.users (id bigint);
            CREATE TABLE archive.users (id bigint, archived_at timestamp with time zone);
            SQL);

        $this->assertCount(2, $database->tables);
        $this->assertCount(1, self::assertTable($database, 'users', 'public')->columns);
        $this->assertCount(2, self::assertTable($database, 'users', 'archive')->columns);
    }

    #[Test]
    public function itPreservesTheCaseOfAQuotedIdentifier(): void
    {
        $database = $this->parser->parse('CREATE TABLE public."Users" ("firstName" text);');

        $this->assertSame('firstName', self::assertColumn($database, 'Users', 'firstName')->name);
    }

    #[Test]
    public function itReturnsAnEmptyDatabaseForADumpWithoutTables(): void
    {
        $this->assertSame([], $this->parser->parse("SET statement_timeout = 0;\n")->tables);
    }
}
