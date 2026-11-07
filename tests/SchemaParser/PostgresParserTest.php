<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\Deferral;
use Hypervel\SchemaParser\ForeignKey;
use Hypervel\SchemaParser\ForeignKeyMatch;
use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\PostgresParser;
use Hypervel\SchemaParser\ReferentialAction;
use Hypervel\SchemaParser\TableReference;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PostgresParserTest extends TestCase
{
    public function testTablesKeepTheirDeclaredSchemaAndQuotedNames(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE reporting.summaries (id bigint NOT NULL);
            CREATE TABLE users (id bigint);
            CREATE TABLE public."Users" ("firstName" text);
            SQL);

        $this->assertSame('reporting', $schema->table('summaries')->schema);
        $this->assertNull($schema->table('users')->schema);
        $this->assertSame('firstName', $schema->table('Users', 'public')->column('firstName')->name);
    }

    #[DataProvider('keywordTypeProvider')]
    public function testKeywordTypesNameCatalogTypes(string $declared, ?string $typeSchema, string $type): void
    {
        $column = (new PostgresParser)->parse("CREATE TABLE t (c {$declared});")->table('t')->column('c');

        $this->assertSame([$typeSchema, $type], [$column->type->schema, $column->type->name]);
    }

    /**
     * Provide SQL-standard type spellings, which name catalog types, and identifiers, which keep their unqualified name.
     *
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function keywordTypeProvider(): iterable
    {
        yield 'integer' => ['integer', 'pg_catalog', 'int4'];
        yield 'bigint' => ['bigint', 'pg_catalog', 'int8'];
        yield 'smallint' => ['smallint', 'pg_catalog', 'int2'];
        yield 'boolean' => ['boolean', 'pg_catalog', 'bool'];
        yield 'double precision' => ['double precision', 'pg_catalog', 'float8'];
        yield 'real' => ['real', 'pg_catalog', 'float4'];
        yield 'character varying' => ['character varying(255)', 'pg_catalog', 'varchar'];
        yield 'character' => ['character(2)', 'pg_catalog', 'bpchar'];
        yield 'timestamp without time zone' => ['timestamp(0) without time zone', 'pg_catalog', 'timestamp'];
        yield 'timestamp with time zone' => ['timestamp with time zone', 'pg_catalog', 'timestamptz'];
        yield 'time with time zone' => ['time with time zone', 'pg_catalog', 'timetz'];
        yield 'bit varying' => ['bit varying(5)', 'pg_catalog', 'varbit'];
        yield 'interval' => ['interval day to second(3)', 'pg_catalog', 'interval'];
        yield 'uuid' => ['uuid', null, 'uuid'];
        yield 'jsonb' => ['jsonb', null, 'jsonb'];
    }

    public function testArrayDimensionsStaySeparateFromModifiers(): void
    {
        $table = (new PostgresParser)->parse('CREATE TABLE t (c character varying(50)[], d integer[][], e numeric(10,2));')->table('t');
        $varchars = $table->column('c')->type;

        $this->assertSame(['pg_catalog', 'varchar', ['50'], 1], [$varchars->schema, $varchars->name, $varchars->modifiers, $varchars->dimensions]);
        $this->assertTrue($varchars->isArray());
        $this->assertSame(2, $table->column('d')->type->dimensions);
        $this->assertSame(['10', '2'], $table->column('e')->type->modifiers);
        $this->assertFalse($table->column('e')->type->isArray());
    }

    public function testIntervalFieldsStaySeparateFromPrecision(): void
    {
        $table = (new PostgresParser)->parse(<<<'SQL'
            CREATE DOMAIN public.span AS INTERVAL DAY TO SECOND(3);
            CREATE TABLE t (a interval year to month, b interval(2), c interval minute[], d public.span, e public.span[]);
            SQL)->table('t');

        $this->assertSame('year to month', $table->column('a')->type->intervalFields);
        $this->assertSame([], $table->column('a')->type->modifiers);
        $this->assertNull($table->column('b')->type->intervalFields);
        $this->assertSame(['2'], $table->column('b')->type->modifiers);
        $this->assertSame('minute', $table->column('c')->type->intervalFields);
        $this->assertSame(1, $table->column('c')->type->dimensions);

        foreach (['d' => 0, 'e' => 1] as $column => $dimensions) {
            $type = $table->column($column)->type;
            $this->assertSame('span', $type->name);
            $this->assertSame($dimensions, $type->dimensions);
            $this->assertSame('interval', $type->base->name);
            $this->assertSame('day to second', $type->base->intervalFields);
            $this->assertSame(['3'], $type->base->modifiers);
        }
    }

    public function testUndeclaredQualifiedTypesStayOpaque(): void
    {
        // Extension types are declared by the extension, not the dump, so there is nothing to resolve them to.
        $schema = (new PostgresParser)->parse('CREATE TABLE t (c public.hstore);');
        $type = $schema->table('t')->column('c')->type;

        $this->assertSame(['public', 'hstore', null], [$type->schema, $type->name, $type->base]);
        $this->assertTrue($schema->isComplete());
    }

    public function testLaterStatementsAddCompositePrimaryKeys(): void
    {
        // pg_dump writes primary keys as separate statements.
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.pivot (a bigint NOT NULL, b bigint NOT NULL);
            ALTER TABLE ONLY public.pivot ADD CONSTRAINT pivot_pkey PRIMARY KEY (a, b);
            SQL);

        $this->assertSame(['a', 'b'], array_column($schema->table('pivot')->primaryKey()->columns, 'name'));
    }

    public function testSequenceDefaultsMarkSerialColumns(): void
    {
        // pg_dump rewrites bigserial into a bigint column and a sequence, so the default is the only remaining evidence.
        $column = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.users (id bigint NOT NULL);
            CREATE SEQUENCE public.users_id_seq AS integer START WITH 1;
            ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);
            SQL)->table('users')->column('id');

        $this->assertTrue($column->autoIncrement);
        $this->assertSame("nextval('public.users_id_seq'::regclass)", $column->default);
    }

    public function testOnlyUnqualifiedSerialNamesAreSerial(): void
    {
        // PostgreSQL compares the folded type name to the serial names exactly.
        $table = (new PostgresParser)->parse('CREATE TABLE t (a SERIAL8, b "serial", c "SERIAL", d public.serial);')->table('t');

        foreach (['a' => 'int8', 'b' => 'int4'] as $name => $type) {
            $column = $table->column($name);
            $this->assertSame(['pg_catalog', $type], [$column->type->schema, $column->type->name]);
            $this->assertTrue($column->autoIncrement);
            $this->assertFalse($column->nullable);
        }

        foreach (['c' => [null, 'SERIAL'], 'd' => ['public', 'serial']] as $name => $type) {
            $column = $table->column($name);
            $this->assertSame($type, [$column->type->schema, $column->type->name]);
            $this->assertFalse($column->autoIncrement);
            $this->assertTrue($column->nullable);
        }
    }

    public function testTableBodyConstraintsStaySeparateFromColumns(): void
    {
        $table = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.t (
                id bigint NOT NULL,
                other_id bigint,
                CONSTRAINT t_check CHECK ((id > 0)),
                CONSTRAINT t_other_fk FOREIGN KEY (other_id) REFERENCES public.other(id)
            );
            SQL)->table('t');

        $this->assertSame(['id', 'other_id'], array_column($table->columns, 'name'));
        $this->assertSame('t_check', $table->checks[0]->name);
        $this->assertSame('t_other_fk', $table->foreignKeys[0]->name);
    }

    public function testOtherObjectsStaySeparateFromTables(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE SCHEMA reporting;
            CREATE EXTENSION IF NOT EXISTS hstore WITH SCHEMA public;
            CREATE TABLE public.users (id bigint);
            CREATE VIEW public.active AS SELECT id FROM public.users;
            CREATE INDEX users_id_idx ON public.users USING btree (id);
            COMMENT ON TABLE public.users IS 'people';
            SQL);

        $this->assertSame(['users'], array_column($schema->tables, 'name'));
        $this->assertSame('SELECT id FROM public.users', $schema->view('active')->definition);
        $this->assertSame('users_id_idx', $schema->table('users')->indexes[0]->name);
        $this->assertSame('people', $schema->table('users')->comment);
        $this->assertTrue($schema->isComplete());
    }

    public function testRoutineBodiesDoNotDeclareTables(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE FUNCTION public.f() RETURNS trigger AS $$
            BEGIN
                -- CREATE TABLE public.decoy (id integer);
                NEW.updated_at = now();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TABLE public.real_table (id bigint);
            SQL);

        $this->assertSame(['real_table'], array_column($schema->tables, 'name'));
    }

    public function testClauseKeywordsWhereAnOperandIsExpectedStayInDefaults(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (
                sum integer DEFAULT 1 + NULL NOT NULL,
                distinct_flag boolean DEFAULT 1 IS DISTINCT FROM NULL,
                explicit integer DEFAULT 1 OPERATOR(pg_catalog.+) NULL NOT NULL,
                call integer DEFAULT app.operator(1) NOT NULL,
                first app.not NOT NULL,
                second app.not NOT NULL
            );
            SQL);
        $table = $schema->table('t');

        $this->assertSame('1 + NULL', $table->column('sum')->default);
        $this->assertFalse($table->column('sum')->nullable);
        $this->assertSame('1 IS DISTINCT FROM NULL', $table->column('distinct_flag')->default);
        $this->assertTrue($table->column('distinct_flag')->nullable);
        $this->assertSame('1 OPERATOR(pg_catalog.+) NULL', $table->column('explicit')->default);
        $this->assertFalse($table->column('explicit')->nullable);
        $this->assertSame('app.operator(1)', $table->column('call')->default);
        $this->assertFalse($table->column('call')->nullable);

        // The second column reuses the first one's cached type, so both must end before NOT NULL.
        foreach (['first', 'second'] as $name) {
            $this->assertSame(['app', 'not'], [$table->column($name)->type->schema, $table->column($name)->type->name]);
            $this->assertFalse($table->column($name)->nullable);
        }

        $this->assertTrue($schema->isComplete());
    }

    public function testDomainDefaultsApplyOnlyToScalarReferences(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE DOMAIN public.label AS text DEFAULT 'label' NOT NULL CHECK (length(VALUE) > 0);
            CREATE DOMAIN public.labels AS public.label[];
            CREATE DOMAIN public.label_chain AS public.label;
            CREATE DOMAIN public.required_labels AS public.labels DEFAULT ARRAY[]::public.label[] NOT NULL;
            ALTER DOMAIN public.labels ADD CONSTRAINT labels_check CHECK (cardinality(VALUE) < 5) NOT VALID;
            CREATE TABLE public.items (
                inherited public.label,
                overridden public.label DEFAULT NULL,
                elements public.label[],
                array_domain public.labels,
                required public.required_labels,
                chained public.label_chain,
                explicitly_required public.label NOT NULL
            );
            SQL);

        $table = $schema->table('items');
        $this->assertSame("'label'", $table->column('inherited')->default);
        $this->assertTrue($table->column('inherited')->nullable);
        $this->assertSame('NULL', $table->column('overridden')->default);
        $this->assertTrue($table->column('overridden')->nullable);
        $this->assertTrue($table->column('elements')->nullable);
        $this->assertNull($table->column('elements')->default);
        $this->assertSame(1, $table->column('elements')->type->dimensions);
        $this->assertSame('text', $table->column('elements')->type->base->name);
        $this->assertTrue($table->column('array_domain')->nullable);
        $this->assertNull($table->column('array_domain')->default);
        $this->assertTrue($table->column('required')->nullable);
        $this->assertTrue($table->column('chained')->nullable);
        $this->assertFalse($table->column('explicitly_required')->nullable);
        $this->assertFalse($schema->domain('label')->nullable);
        $this->assertFalse($schema->domain('label_chain')->nullable);
        $this->assertFalse($schema->domain('required_labels')->nullable);
        $this->assertSame('ARRAY[]::public.label[]', $table->column('required')->default);
        $this->assertCount(1, $schema->domain('labels')->checks);
        $this->assertFalse($schema->domain('labels')->checks[0]->valid);
        $this->assertTrue($schema->isComplete());
    }

    public function testInheritancePreservesLocalExpressionsOrderAndConstraintBoundaries(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.first (
                id integer GENERATED ALWAYS AS IDENTITY,
                x integer DEFAULT 1,
                calculated integer GENERATED ALWAYS AS (x + 1) STORED,
                restricted integer,
                CONSTRAINT restricted_nn NOT NULL restricted NO INHERIT,
                CONSTRAINT positive CHECK (x > 0),
                CONSTRAINT local_check CHECK (x < 100) NO INHERIT
            );
            COMMENT ON COLUMN public.first.x IS 'parent only';
            CREATE TABLE public.second (
                x integer DEFAULT 2,
                calculated integer GENERATED ALWAYS AS (x + 2) STORED
            );
            CREATE TABLE public.child (
                x integer DEFAULT NULL,
                calculated integer GENERATED ALWAYS AS (x + 3) STORED,
                last text,
                CONSTRAINT positive CHECK (x > 0)
            ) INHERITS (public.first, public.second);
            ALTER TABLE ONLY public.first ALTER COLUMN x SET DEFAULT 5;
            SQL);

        $child = $schema->table('child');
        $this->assertSame(['id', 'x', 'calculated', 'restricted', 'last'], array_column($child->columns, 'name'));
        $this->assertSame('NULL', $child->column('x')->default);
        $this->assertSame('(x + 3)', $child->column('calculated')->generation->expression);
        $this->assertSame(GenerationType::Stored, $child->column('calculated')->generation->type);
        $this->assertNull($child->column('id')->identity);
        $this->assertFalse($child->column('id')->autoIncrement);
        $this->assertFalse($child->column('id')->nullable);
        $this->assertNull($child->column('x')->comment);
        $this->assertTrue($child->column('restricted')->nullable);
        $this->assertSame(['positive'], array_column($child->checks, 'name'));
        $this->assertSame('5', $schema->table('first')->column('x')->default);
        $this->assertTrue($schema->isComplete());
    }

    public function testSerialSequenceDefaultsAreInheritedOverriddenAndReplaced(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE p (id serial);
            CREATE TABLE plain (id integer);
            CREATE TABLE redeclared (id integer) INHERITS (p);
            CREATE TABLE later_parent () INHERITS (plain, p);
            CREATE TABLE overridden (id integer DEFAULT 5) INHERITS (p);
            CREATE TABLE five (id integer DEFAULT 5);
            CREATE TABLE six (id integer DEFAULT 6);
            CREATE TABLE local_serial (id serial) INHERITS (five, six);
            CREATE TABLE dropped (id serial);
            CREATE TABLE dropped_child () INHERITS (dropped);
            ALTER TABLE dropped ALTER COLUMN id DROP DEFAULT;
            CREATE TABLE replaced (id serial);
            ALTER TABLE replaced ALTER COLUMN id SET DEFAULT 5;
            CREATE TABLE renumbered (id serial);
            ALTER TABLE renumbered ALTER COLUMN id SET DEFAULT nextval('other_seq');
            SQL);

        foreach (['p', 'redeclared', 'later_parent', 'local_serial'] as $name) {
            $this->assertTrue($schema->table($name)->column('id')->autoIncrement, $name);
            $this->assertNull($schema->table($name)->column('id')->default, $name);
        }

        foreach (['overridden' => '5', 'dropped' => null, 'dropped_child' => null, 'replaced' => '5'] as $name => $default) {
            $this->assertFalse($schema->table($name)->column('id')->autoIncrement, $name);
            $this->assertSame($default, $schema->table($name)->column('id')->default, $name);
        }

        $this->assertTrue($schema->table('renumbered')->column('id')->autoIncrement);
        // The local serial resolves the conflict between the inherited defaults.
        $this->assertTrue($schema->isComplete());
    }

    public function testRecursiveAlterSkipsATableRecreatedAfterItsChildWasDropped(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE parent (id integer);
            CREATE TABLE child () INHERITS (parent);
            CREATE TABLE part (id integer) PARTITION BY RANGE (id);
            CREATE TABLE part_one PARTITION OF part FOR VALUES FROM (1) TO (10);
            DROP TABLE child, part_one;
            CREATE TABLE child (id integer);
            CREATE TABLE part_one (id integer);
            ALTER TABLE parent ALTER COLUMN id SET DEFAULT 9;
            ALTER TABLE part ALTER COLUMN id SET DEFAULT 9;
            SQL);

        $this->assertNull($schema->table('child')->column('id')->default);
        $this->assertNull($schema->table('part_one')->column('id')->default);
    }

    public function testDroppingAChildUnlinksItWhenItsParentNameBecomesAmbiguous(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.p (x integer);
            CREATE TABLE public.c () INHERITS (p);
            CREATE TABLE other.p (x integer);
            DROP TABLE public.c;
            ALTER TABLE public.p ALTER COLUMN x SET DEFAULT 1;
            SQL);

        $this->assertNull($schema->table('c'));
        $this->assertSame('1', $schema->table('p', 'public')->column('x')->default);
    }

    public function testRecursiveAlterVisitsDiamondDescendantsOnceAndDoesNotCopyPrimaryKeys(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE parent (id integer);
            CREATE TABLE left_child () INHERITS (parent);
            CREATE TABLE right_child () INHERITS (parent);
            CREATE TABLE bottom_child (
                CONSTRAINT positive CHECK (id > 0),
                CONSTRAINT bounded CHECK (id < 100)
            ) INHERITS (left_child, right_child);
            ALTER TABLE parent ALTER COLUMN id SET DEFAULT 9, ADD CONSTRAINT positive CHECK (id > 0) NOT VALID;
            ALTER TABLE parent ADD CONSTRAINT bounded CHECK (id < 100) NOT ENFORCED;
            CREATE UNIQUE INDEX parent_id ON parent (id);
            ALTER TABLE parent ADD CONSTRAINT parent_pk PRIMARY KEY USING INDEX parent_id;
            SQL);

        $bottom = $schema->table('bottom_child');
        $this->assertSame('9', $bottom->column('id')->default);
        $this->assertSame(['positive', 'bounded'], array_column($bottom->checks, 'name'));
        $this->assertTrue($bottom->checks[0]->valid);
        $this->assertTrue($bottom->checks[1]->valid);
        $this->assertTrue($bottom->checks[1]->enforced);
        $this->assertFalse($schema->table('left_child')->checks[0]->valid);
        $this->assertFalse($schema->table('left_child')->checks[1]->valid);
        $this->assertFalse($schema->table('left_child')->checks[1]->enforced);
        $this->assertFalse($bottom->column('id')->nullable);
        $this->assertNull($bottom->primaryKey());
        $this->assertCount(1, $schema->table('parent')->indexes);
        $this->assertSame('parent_pk', $schema->table('parent')->primaryKey()->name);
        $this->assertTrue($schema->isComplete());
    }

    public function testUnvalidatedNotNullIsUncertainOnlyForExistingNullableRows(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE parent (id integer);
            CREATE TABLE existing () INHERITS (parent);
            ALTER TABLE parent ADD CONSTRAINT parent_nn NOT NULL id NOT VALID;
            ALTER TABLE parent ADD CONSTRAINT positive CHECK (id > 0) NOT VALID;
            ALTER TABLE parent ADD CONSTRAINT bounded CHECK (id < 100) NOT ENFORCED;
            CREATE TABLE later () INHERITS (parent);
            CREATE TABLE independently_nonnull (id integer NOT NULL);
            ALTER TABLE independently_nonnull ADD CONSTRAINT same_nn NOT NULL id NOT VALID;
            SQL);

        $this->assertTrue($schema->table('parent')->column('id')->nullable);
        $this->assertCount(1, $schema->table('parent')->issues);
        $this->assertTrue($schema->table('existing')->column('id')->nullable);
        $this->assertCount(1, $schema->table('existing')->issues);
        $this->assertFalse($schema->table('later')->column('id')->nullable);
        $this->assertTrue($schema->table('later')->isComplete());
        $this->assertFalse($schema->table('existing')->checks[0]->valid);
        $this->assertTrue($schema->table('later')->checks[0]->valid);
        $this->assertFalse($schema->table('later')->checks[1]->valid);
        $this->assertFalse($schema->table('later')->checks[1]->enforced);
        $this->assertFalse($schema->table('independently_nonnull')->column('id')->nullable);
        $this->assertTrue($schema->table('independently_nonnull')->isComplete());
    }

    public function testDomainRestrictionDoesNotHideUnvalidatedColumnNullability(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE DOMAIN required_value AS integer NOT NULL;
            CREATE TABLE values_table (value required_value);
            ALTER TABLE values_table ADD CONSTRAINT value_nn NOT NULL value NOT VALID;
            SQL);

        $this->assertFalse($schema->domain('required_value')->nullable);
        $this->assertTrue($schema->table('values_table')->column('value')->nullable);
        $this->assertCount(1, $schema->table('values_table')->issues);
        $this->assertStringContainsString('has not been validated', $schema->table('values_table')->issues[0]->message);
    }

    public function testIndexKeysPayloadsAndForeignKeyOptionsRemainDistinct(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.parent ("id key" integer PRIMARY KEY);
            CREATE TABLE public.child (id integer, email text COLLATE "C", payload text);
            CREATE UNIQUE INDEX child_email ON ONLY public.child USING btree
                (email COLLATE "C" pg_catalog.text_pattern_ops DESC NULLS LAST)
                INCLUDE (payload) NULLS NOT DISTINCT WITH (fillfactor=80);
            ALTER TABLE ONLY public.child ADD CONSTRAINT email_unique UNIQUE USING INDEX child_email DEFERRABLE INITIALLY DEFERRED;
            CREATE INDEX expression_idx ON public.child (id, lower(email)) WHERE (id > 0);
            ALTER TABLE ONLY public.child ADD CONSTRAINT child_fk FOREIGN KEY (id)
                REFERENCES public.parent MATCH FULL ON UPDATE CASCADE ON DELETE SET NULL
                DEFERRABLE INITIALLY IMMEDIATE NOT VALID;
            ALTER TABLE ONLY public.child ADD CONSTRAINT unchecked_fk FOREIGN KEY (id)
                REFERENCES public.parent NOT ENFORCED;
            SQL);

        $table = $schema->table('child');
        $unique = $table->indexes[0];
        $this->assertCount(2, $table->indexes);
        $this->assertSame('email_unique', $unique->name);
        $this->assertTrue($unique->constraint);
        $this->assertTrue($unique->nullsNotDistinct);
        $this->assertSame(['payload'], $unique->include);
        $this->assertSame(Deferral::Deferred, $unique->deferral);
        $this->assertSame('"C"', $unique->columns[0]->collation);
        $this->assertSame('pg_catalog.text_pattern_ops', $unique->columns[0]->operatorClass);
        $this->assertTrue($unique->columns[0]->descending);
        $this->assertFalse($unique->columns[0]->nullsFirst);
        $this->assertNull($table->indexes[1]->columns[1]->name);
        $this->assertSame('lower(email)', $table->indexes[1]->columns[1]->expression);
        $this->assertSame('(id > 0)', $table->indexes[1]->where);
        $foreign = $table->foreignKeys[0];
        $this->assertSame(['id key'], $foreign->foreignColumns);
        $this->assertSame(ForeignKeyMatch::Full, $foreign->match);
        $this->assertSame(ReferentialAction::Cascade, $foreign->onUpdate);
        $this->assertSame(ReferentialAction::SetNull, $foreign->onDelete);
        $this->assertSame(Deferral::Immediate, $foreign->deferral);
        $this->assertFalse($foreign->valid);
        $this->assertFalse($table->foreignKeys[1]->valid);
        $this->assertFalse($table->foreignKeys[1]->enforced);
        $this->assertTrue($schema->isComplete());
    }

    public function testUsingIndexTablespacePlacesANewConstraintIndex(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (id integer PRIMARY KEY USING INDEX TABLESPACE fast, code text);
            ALTER TABLE ONLY t ADD CONSTRAINT t_code_key UNIQUE (code) INCLUDE (id) USING INDEX TABLESPACE fast;
            SQL);

        $table = $schema->table('t');
        $this->assertSame([null, 't_code_key'], array_column($table->indexes, 'name'));
        $this->assertTrue($table->indexes[0]->primary);
        $this->assertSame(['id'], array_column($table->indexes[0]->columns, 'name'));
        $this->assertSame(['code'], array_column($table->indexes[1]->columns, 'name'));
        $this->assertSame(['id'], $table->indexes[1]->include);
        $this->assertTrue($schema->isComplete());
    }

    public function testUnnamedIndexesKeepTheirKeys(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (id integer, code text);
            CREATE INDEX ON t USING HASH (code);
            SQL);

        $index = $schema->table('t')->indexes[0];
        $this->assertNull($index->name);
        $this->assertSame('hash', $index->type);
        $this->assertSame(['code'], array_column($index->columns, 'name'));
        $this->assertTrue($schema->isComplete());
    }

    public function testTemporalForeignKeysRetainColumnsWithoutRejectingPeriodNames(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE parent (
                id integer, valid_at daterange, period integer, "PERIOD" integer,
                PRIMARY KEY (id, valid_at WITHOUT OVERLAPS),
                UNIQUE (period, "PERIOD")
            );
            CREATE TABLE child (id integer, valid_at daterange, period integer, "PERIOD" integer);
            ALTER TABLE ONLY child ADD CONSTRAINT temporal_fk FOREIGN KEY (id, PERIOD valid_at)
                REFERENCES parent (id, PERIOD valid_at);
            ALTER TABLE ONLY child ADD CONSTRAINT ordinary_fk FOREIGN KEY (period, "PERIOD")
                REFERENCES parent (period, "PERIOD");
            CREATE TABLE implicit_child (id integer, valid_at daterange);
            ALTER TABLE ONLY implicit_child ADD CONSTRAINT implicit_fk FOREIGN KEY (id, PERIOD valid_at) REFERENCES parent;
            CREATE TABLE part_child (id integer, valid_at daterange) PARTITION BY LIST (id);
            CREATE TABLE part_child_a (id integer, valid_at daterange);
            ALTER TABLE part_child ATTACH PARTITION part_child_a FOR VALUES IN (1);
            ALTER TABLE part_child ADD CONSTRAINT part_temporal_fk FOREIGN KEY (id, PERIOD valid_at) REFERENCES parent (id, PERIOD valid_at);
            CREATE TABLE early_child (id integer, valid_at daterange, CONSTRAINT early_fk FOREIGN KEY (id, PERIOD valid_at) REFERENCES parent (id, PERIOD valid_at)) PARTITION BY LIST (id);
            CREATE TABLE early_child_a (id integer, valid_at daterange);
            ALTER TABLE early_child ATTACH PARTITION early_child_a FOR VALUES IN (1);
            SQL);

        $child = $schema->table('child');
        $this->assertSame(['id', 'valid_at'], $child->foreignKeys[0]->columns);
        $this->assertSame(['id', 'valid_at'], $child->foreignKeys[0]->foreignColumns);
        $this->assertSame(['period', 'PERIOD'], $child->foreignKeys[1]->columns);
        $this->assertSame(['period', 'PERIOD'], $child->foreignKeys[1]->foreignColumns);
        $this->assertCount(1, $child->issues);
        $this->assertStringContainsString('Temporal foreign key', $child->issues[0]->message);
        $this->assertCount(1, $schema->table('parent')->issues);
        $this->assertSame([], $schema->issues);

        // The temporal key part has no column name to stand for the omitted referenced columns.
        $implicit = $schema->table('implicit_child');
        $this->assertSame([], $implicit->foreignKeys[0]->foreignColumns);
        $this->assertSame([
            'Temporal foreign key semantics are not represented.',
            'The referenced columns of foreign table [parent] could not be resolved.',
        ], array_column($implicit->issues, 'message'));

        // A partition's copy is as partly represented as its parent's, whichever was declared first.
        foreach (['part_child_a' => 'part_temporal_fk', 'early_child_a' => 'early_fk'] as $name => $foreignKey) {
            $partition = $schema->table($name);
            $this->assertSame([$foreignKey], array_column($partition->foreignKeys, 'name'), $name);
            $this->assertSame(['Temporal foreign key semantics are not represented.'], array_column($partition->issues, 'message'), $name);
        }
    }

    public function testRecursiveNullabilityRemovalMarksUnresolvedChildConstraints(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE parent (id integer NOT NULL);
            CREATE TABLE plain () INHERITS (parent);
            CREATE TABLE locally_required (id integer NOT NULL) INHERITS (parent);
            ALTER TABLE parent ALTER COLUMN id DROP NOT NULL;
            SQL);

        $this->assertTrue($schema->table('parent')->column('id')->nullable);
        $this->assertTrue($schema->table('parent')->isComplete());
        $this->assertTrue($schema->table('plain')->column('id')->nullable);
        $this->assertCount(1, $schema->table('plain')->issues);
        $this->assertTrue($schema->table('locally_required')->column('id')->nullable);
        $this->assertCount(1, $schema->table('locally_required')->issues);
    }

    public function testQuotedIdentityLiteralDecodingAndPartitionAttachment(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TYPE "schema.dot"."mood.name" AS ENUM (E'line\nbreak', $$a'b$$, 'one'
            'two');
            CREATE TABLE "schema.dot"."parent.table" ("key.name" integer, value text);
            CREATE TABLE "schema.dot"."child.table" ("key.name" integer, value text);
            ALTER TABLE ONLY "schema.dot"."parent.table" ATTACH PARTITION "schema.dot"."child.table" FOR VALUES FROM (1) TO (10);
            COMMENT ON COLUMN "schema.dot"."child.table"."key.name" IS E'a\tb';
            COMMENT ON TABLE "schema.dot"."child.table" IS 'removed';
            COMMENT ON TABLE "schema.dot"."child.table" IS NULL;
            CREATE VIEW "schema.dot"."v.name" AS SELECT value FROM "schema.dot"."child.table" WITH LOCAL CHECK OPTION;
            CREATE MATERIALIZED VIEW mv AS SELECT 1 AS result WITH NO DATA;
            SQL);

        $this->assertSame(["line\nbreak", "a'b", 'onetwo'], $schema->enum('mood.name', 'schema.dot')->values);
        $child = $schema->table('child.table', 'schema.dot');
        $this->assertSame('parent.table', $child->partitionOf->name);
        $this->assertSame('schema.dot', $child->partitionOf->schema);
        $this->assertSame('FOR VALUES FROM (1) TO (10)', $child->partitionBounds);
        $this->assertSame([], $child->parents);
        $this->assertSame("a\tb", $child->column('key.name')->comment);
        $this->assertNull($child->comment);
        $this->assertSame('SELECT value FROM "schema.dot"."child.table"', $schema->view('v.name')->definition);
        $this->assertTrue($schema->view('mv')->materialized);
        $this->assertSame('SELECT 1 AS result', $schema->view('mv')->definition);
        $this->assertTrue($schema->isComplete());
    }

    public function testPartitionsGetTheForeignKeysADumpAddsToTheirParent(): void
    {
        // pg_dump attaches partitions first and writes a partitioned table's foreign keys once, without ONLY.
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.r (id integer NOT NULL);
            CREATE TABLE public.p (k integer, ref_id integer) PARTITION BY RANGE (k);
            CREATE TABLE public.c1 (k integer, ref_id integer, CONSTRAINT root_fk CHECK ((k >= 0)));
            CREATE TABLE public.c2 (k integer, ref_id integer) PARTITION BY RANGE (k);
            CREATE TABLE public.c2_a (k integer, ref_id integer);
            CREATE TABLE public.inh_parent (ref_id integer);
            CREATE TABLE public.inh_child () INHERITS (public.inh_parent);
            ALTER TABLE ONLY public.p ATTACH PARTITION public.c1 FOR VALUES FROM (0) TO (10);
            ALTER TABLE ONLY public.p ATTACH PARTITION public.c2 FOR VALUES FROM (10) TO (20);
            ALTER TABLE ONLY public.c2 ATTACH PARTITION public.c2_a FOR VALUES FROM (10) TO (20);
            ALTER TABLE ONLY public.r ADD CONSTRAINT r_pkey PRIMARY KEY (id);
            CREATE INDEX p_ref_idx ON ONLY public.p USING btree (ref_id);
            ALTER TABLE public.p ADD CONSTRAINT fk_one FOREIGN KEY (ref_id) REFERENCES public.r(id);
            ALTER TABLE public.p ADD CONSTRAINT root_fk FOREIGN KEY (ref_id) REFERENCES public.r(id);
            ALTER TABLE ONLY public.inh_parent ADD CONSTRAINT inh_fk FOREIGN KEY (ref_id) REFERENCES public.r(id);
            SQL);

        // Identical parent foreign keys each get their own copy, and a name the partition already uses is left to the server.
        $this->assertSame(['fk_one', null], array_column($schema->table('c1')->foreignKeys, 'name'));
        $this->assertEquals(new ForeignKey(null, ['ref_id'], new TableReference('r', 'public'), ['id']), $schema->table('c1')->foreignKeys[1]);
        $this->assertSame(['fk_one', 'root_fk'], array_column($schema->table('c2')->foreignKeys, 'name'));
        $this->assertSame(['fk_one', 'root_fk'], array_column($schema->table('c2_a')->foreignKeys, 'name'));
        $this->assertSame([], $schema->table('inh_child')->foreignKeys);
        $this->assertTrue($schema->isComplete());
    }

    public function testAttachedPartitionsAdoptMatchingForeignKeysAndReportIndexesFromTheirParent(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE public.r (id integer PRIMARY KEY);
            CREATE TABLE p (k integer, ref_id integer, PRIMARY KEY (k), CONSTRAINT parent_fk FOREIGN KEY (ref_id) REFERENCES public.r (id)) PARTITION BY RANGE (k);
            CREATE TABLE own (k integer NOT NULL, ref_id integer, CONSTRAINT own_fk FOREIGN KEY (ref_id) REFERENCES r);
            CREATE TABLE weak (k integer NOT NULL, ref_id integer);
            ALTER TABLE weak ADD CONSTRAINT weak_fk FOREIGN KEY (ref_id) REFERENCES r (id) NOT VALID;
            ALTER TABLE p ATTACH PARTITION own FOR VALUES FROM (0) TO (10);
            ALTER TABLE p ATTACH PARTITION weak FOR VALUES FROM (10) TO (20);
            CREATE TABLE q (k integer) PARTITION BY RANGE (k);
            CREATE TABLE q_a (k integer);
            ALTER TABLE q ATTACH PARTITION q_a FOR VALUES FROM (0) TO (10);
            CREATE INDEX q_only ON ONLY q (k);
            CREATE TABLE s (k integer) PARTITION BY RANGE (k);
            CREATE TABLE s_a (k integer);
            ALTER TABLE s ATTACH PARTITION s_a FOR VALUES FROM (0) TO (10);
            CREATE INDEX s_k ON s (k);
            CREATE TABLE u (k integer) PARTITION BY RANGE (k);
            CREATE TABLE u_a (k integer);
            ALTER TABLE u ATTACH PARTITION u_a FOR VALUES FROM (0) TO (10);
            ALTER TABLE u ADD PRIMARY KEY (k);
            CREATE TABLE v (k integer) PARTITION BY RANGE (k);
            CREATE TABLE v_a (k integer);
            ALTER TABLE v ATTACH PARTITION v_a FOR VALUES FROM (0) TO (10);
            ALTER TABLE v ADD CONSTRAINT v_ex EXCLUDE USING btree (k WITH =);
            CREATE TABLE inh (k integer);
            CREATE TABLE inh_child () INHERITS (inh);
            CREATE INDEX inh_k ON inh (k);
            CREATE TABLE w (k integer, EXCLUDE USING btree (k WITH =)) PARTITION BY RANGE (k);
            CREATE TABLE w_a (k integer) PARTITION BY RANGE (k);
            CREATE TABLE w_a_1 (k integer);
            ALTER TABLE w ATTACH PARTITION w_a FOR VALUES FROM (0) TO (10);
            ALTER TABLE w_a ATTACH PARTITION w_a_1 FOR VALUES FROM (0) TO (5);
            SQL);
        $indexes = ['Indexes the partition gets from its parent table are not represented.'];

        // An equivalent foreign key is adopted however its reference is spelled; a NOT VALID one is not.
        $this->assertSame(['own_fk'], array_column($schema->table('own')->foreignKeys, 'name'));
        $this->assertSame(['weak_fk', 'parent_fk'], array_column($schema->table('weak')->foreignKeys, 'name'));

        // An exclusion index is not represented on the parent either, and reaches partitions attached below w_a later.
        foreach (['own', 'weak', 's_a', 'u_a', 'v_a', 'w_a', 'w_a_1'] as $name) {
            $this->assertSame($indexes, array_column($schema->table($name)->issues, 'message'), $name);
        }

        foreach (['q_a', 'inh_child'] as $name) {
            $this->assertSame([], $schema->table($name)->issues, $name);
        }

        $this->assertFalse($schema->table('u_a')->column('k')->nullable);
    }

    public function testPartitionForeignKeysWithUnresolvableReferencesAreReportedInsteadOfCopied(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE SCHEMA main;
            CREATE SCHEMA other;
            SET search_path = main, pg_catalog;
            CREATE TABLE main.r (id integer PRIMARY KEY);
            CREATE TABLE other.r (id integer PRIMARY KEY);
            CREATE TABLE main.p (k integer, ref_id integer) PARTITION BY RANGE (k);
            CREATE TABLE main.c (k integer, ref_id integer, CONSTRAINT own_fk FOREIGN KEY (ref_id) REFERENCES r (id)) PARTITION BY RANGE (k);
            ALTER TABLE main.p ATTACH PARTITION main.c FOR VALUES FROM (0) TO (10);
            ALTER TABLE main.p ADD CONSTRAINT parent_fk FOREIGN KEY (ref_id) REFERENCES main.r (id);
            CREATE TABLE main.q (k integer, ref_id integer) PARTITION BY RANGE (k);
            CREATE TABLE main.d (k integer, ref_id integer, CONSTRAINT own_fk FOREIGN KEY (ref_id) REFERENCES outside_a (id));
            ALTER TABLE main.q ATTACH PARTITION main.d FOR VALUES FROM (0) TO (10);
            ALTER TABLE main.q ADD CONSTRAINT parent_fk FOREIGN KEY (ref_id) REFERENCES outside_b (id);
            CREATE TABLE main.leaf (k integer, ref_id integer);
            ALTER TABLE main.c ATTACH PARTITION main.leaf FOR VALUES FROM (0) TO (5);
            SQL);
        $unresolved = ['The foreign keys the partition gets from its parent table could not be determined.'];

        // The parser does not follow search_path, so it cannot tell whether PostgreSQL adopted own_fk,
        // and a partition attached below later inherits that uncertainty.
        foreach (['c', 'leaf'] as $name) {
            $partition = $schema->table($name, 'main');
            $this->assertSame(['own_fk'], array_column($partition->foreignKeys, 'name'), $name);
            $this->assertSame($unresolved, array_column($partition->issues, 'message'), $name);
        }

        // Tables with different names are different even when neither is in the dump.
        $this->assertSame(['own_fk', 'parent_fk'], array_column($schema->table('d', 'main')->foreignKeys, 'name'));
        $this->assertSame([], $schema->table('d', 'main')->issues);
    }

    public function testStandardConformingStringsDecideHowLaterLiteralsAreDecoded(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            SET standard_conforming_strings = off;
            CREATE TYPE escaped AS ENUM ('a\\b', 'it\'s');
            CREATE TABLE t (id integer, label public.tagged('a\\b'));
            COMMENT ON TABLE t IS 'tab\there';
            SET standard_conforming_strings TO on;
            CREATE TYPE plain AS ENUM ('a\\b');
            CREATE TABLE u (label public.tagged('a\\b'));
            SQL);

        $this->assertSame(['a\b', "it's"], $schema->enum('escaped')->values);
        $this->assertSame("tab\there", $schema->table('t')->comment);
        $this->assertSame(['a\\\b'], $schema->enum('plain')->values);
        $this->assertSame(['a\b'], $schema->table('t')->column('label')->type->modifiers);
        $this->assertSame(['a\\\b'], $schema->table('u')->column('label')->type->modifiers);
    }

    #[DataProvider('booleanSettingProvider')]
    public function testStandardConformingStringsReadPostgresBooleanSpellings(string $off, string $on): void
    {
        $schema = (new PostgresParser)->parse(<<<SQL
            {$off};
            COMMENT ON SCHEMA public IS 'it\\'s';
            CREATE TYPE escaped AS ENUM ('a\\\\b');
            {$on};
            CREATE TYPE plain AS ENUM ('a\\\\b');
            SQL);

        $this->assertSame(['a\b'], $schema->enum('escaped')->values);
        $this->assertSame(['a\\\b'], $schema->enum('plain')->values);
    }

    /**
     * Provide statements that turn standard_conforming_strings off and then on again.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function booleanSettingProvider(): iterable
    {
        yield 'quoted values' => ["SET standard_conforming_strings = 'off'", 'SET standard_conforming_strings = "ON"'];
        yield 'booleans' => ['SET SESSION standard_conforming_strings TO false', 'SET SESSION standard_conforming_strings = true'];
        yield 'numbers' => ['SET standard_conforming_strings = 0', 'SET standard_conforming_strings = 1'];
        yield 'prefixes' => ["SET standard_conforming_strings = 'of'", 'SET standard_conforming_strings = y'];
    }

    public function testLongRunsOfDoubledQuotesStayOneToken(): void
    {
        $quotes = str_repeat("''", 300);
        $name = str_repeat('""', 300);
        $schema = (new PostgresParser)->parse(<<<SQL
            CREATE TABLE plain (id integer);
            COMMENT ON TABLE plain IS '{$quotes}';
            CREATE TABLE "{$name}" (id integer);
            SET standard_conforming_strings = off;
            CREATE TABLE escaped (id integer);
            COMMENT ON TABLE escaped IS '{$quotes}';
            SQL);

        $this->assertSame(str_repeat("'", 300), $schema->table('plain')->comment);
        $this->assertSame(str_repeat("'", 300), $schema->table('escaped')->comment);
        $this->assertNotNull($schema->table(str_repeat('"', 300)));
        $this->assertTrue($schema->isComplete());
    }

    public function testTemporaryDeclarationsDoNotReplacePersistentTables(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (id integer);
            CREATE TEMP TABLE t (temporary integer);
            CREATE TABLE pg_temp.hidden (id integer);
            CREATE TABLE "PG_TEMP".visible (id integer);
            CREATE UNLOGGED TABLE durable (id integer);
            SQL);

        $this->assertSame(['t', 'visible', 'durable'], array_column($schema->tables, 'name'));
        $this->assertSame(['id'], array_column($schema->table('t')->columns, 'name'));
    }

    public function testConditionalDeclarationsKeepExistingDefinitions(): void
    {
        // Tables, views and indexes share one relation namespace per schema.
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (id integer, code text);
            CREATE TABLE IF NOT EXISTS t (replacement integer);
            CREATE TABLE IF NOT EXISTS added (id integer);
            CREATE INDEX t_index ON t (id);
            CREATE INDEX IF NOT EXISTS t_index ON t (code);
            CREATE INDEX IF NOT EXISTS t ON t (code);
            CREATE TABLE IF NOT EXISTS t_index (id integer);
            CREATE MATERIALIZED VIEW mv AS SELECT 1 AS original;
            CREATE MATERIALIZED VIEW IF NOT EXISTS mv AS SELECT 2 AS replacement;
            CREATE MATERIALIZED VIEW IF NOT EXISTS t AS SELECT 3 AS replacement;
            CREATE VIEW v AS SELECT 4 AS original;
            CREATE TABLE IF NOT EXISTS v (id integer);
            CREATE TABLE other.t_index (id integer);
            SQL);

        $this->assertSame(['t', 'added', 't_index'], array_column($schema->tables, 'name'));
        $this->assertSame('other', $schema->table('t_index')->schema);
        $this->assertSame(['id', 'code'], array_column($schema->table('t')->columns, 'name'));
        $this->assertCount(1, $schema->table('t')->indexes);
        $this->assertSame(['id'], array_column($schema->table('t')->indexes[0]->columns, 'name'));
        $this->assertSame(['mv', 'v'], array_column($schema->views, 'name'));
        $this->assertSame('SELECT 1 AS original', $schema->view('mv')->definition);
        $this->assertTrue($schema->isComplete());
    }

    public function testConditionalDeclarationsFollowIndexChanges(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (id integer, code text);
            ALTER TABLE ONLY t ADD CONSTRAINT t_code_key UNIQUE (code);
            CREATE INDEX IF NOT EXISTS t_code_key ON t (id);
            CREATE UNIQUE INDEX t_id_key ON t (id);
            ALTER TABLE ONLY t ADD CONSTRAINT t_pkey PRIMARY KEY USING INDEX t_id_key;
            CREATE INDEX IF NOT EXISTS t_pkey ON t (code);
            CREATE INDEX IF NOT EXISTS t_id_key ON t (code);
            CREATE INDEX t_drop_index ON t (id);
            DROP INDEX t_drop_index;
            CREATE INDEX IF NOT EXISTS t_drop_index ON t (code);
            CREATE TABLE dropped (id integer);
            CREATE INDEX dropped_index ON dropped (id);
            DROP TABLE dropped;
            CREATE TABLE IF NOT EXISTS dropped_index (id integer);
            SQL);

        // Adopting an index renames it to the constraint, freeing its old name.
        $indexes = $schema->table('t')->indexes;
        $this->assertSame(['t_code_key', 't_pkey', 't_id_key', 't_drop_index'], array_column($indexes, 'name'));
        $this->assertSame(['code'], array_column($indexes[2]->columns, 'name'));
        $this->assertSame(['code'], array_column($indexes[3]->columns, 'name'));
        $this->assertSame(['t', 'dropped_index'], array_column($schema->tables, 'name'));
    }

    public function testPrimaryKeysDeclaredBeforeTheirColumnsMakeThemNotNull(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE parent (PRIMARY KEY (id), id integer);
            CREATE TABLE child () INHERITS (parent);
            SQL);

        $this->assertFalse($schema->table('parent')->column('id')->nullable);
        $this->assertFalse($schema->table('child')->column('id')->nullable);
        $this->assertSame([], $schema->table('child')->indexes);
        $this->assertTrue($schema->isComplete());
    }

    public function testForeignTableOptionsAndPartitionBoundsStaySeparate(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TYPE public.options AS ENUM ('one');
            CREATE FOREIGN TABLE foreign_records (
                id integer OPTIONS (column_name 'remote_id') NOT NULL,
                label public.options DEFAULT 'one'::public.options
            ) SERVER remote_server OPTIONS (table_name 'records');
            CREATE TABLE parent (id integer);
            CREATE FOREIGN TABLE remote PARTITION OF parent FOR VALUES FROM (1) TO (10) SERVER remote_server;
            SQL);

        $this->assertTrue($schema->table('foreign_records')->isComplete());
        $this->assertFalse($schema->table('foreign_records')->column('id')->nullable);
        $this->assertSame('options', $schema->table('foreign_records')->column('label')->type->name);
        $this->assertSame("'one'::public.options", $schema->table('foreign_records')->column('label')->default);
        $this->assertSame('FOR VALUES FROM (1) TO (10)', $schema->table('remote')->partitionBounds);
        $this->assertFalse($schema->table('remote')->isComplete());
    }

    #[DataProvider('unresolvedSchemaChanges')]
    public function testUnresolvedDependenciesInvalidateTheSchemaInventory(string $statement): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TYPE public.composite AS (part integer);
            CREATE TYPE public.mood AS ENUM ('one');
            CREATE SEQUENCE public.counter;
            CREATE TABLE public.records (id integer DEFAULT nextval('public.counter'), value public.composite);
            CREATE VIEW public.report AS SELECT id FROM public.records;
            SQL
            . "\n" . $statement);

        $this->assertCount(1, $schema->issues);
        $this->assertFalse($schema->isComplete());
        foreach ($schema->tables as $table) {
            $this->assertSame([], $table->issues);
        }
    }

    /**
     * Provide changes whose effects extend beyond an immediately represented object.
     */
    public static function unresolvedSchemaChanges(): array
    {
        return [
            ['ALTER TABLE public.records RENAME TO renamed;'],
            ['ALTER TABLE public.report SET SCHEMA archive;'],
            ['ALTER TYPE public.composite RENAME TO renamed;'],
            ["ALTER TYPE public.mood ADD VALUE 'two';"],
            ["ALTER TYPE public.mood RENAME VALUE 'one' TO 'first';"],
            ['DROP TYPE public.composite CASCADE;'],
            ['DROP SCHEMA public CASCADE;'],
            ['DROP SEQUENCE public.counter CASCADE;'],
            ['ALTER TABLE public.records DROP COLUMN id CASCADE;'],
        ];
    }

    public function testQuotedDefaultsAndLocalRenamesDoNotInvalidateTableNames(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE t (value text);
            ALTER TABLE t ALTER COLUMN value SET DEFAULT $$RENAME TO renamed$$;
            ALTER TABLE t RENAME COLUMN value TO renamed;
            SQL);

        $this->assertSame([], $schema->issues);
        $this->assertCount(1, $schema->table('t')->issues);
        $this->assertSame('$$RENAME TO renamed$$', $schema->table('t')->column('value')->default);
    }

    public function testDropListsKeepNamesThatResembleOptionsAndUpdateViewLookup(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE first_table (id integer);
            CREATE TABLE cascade (id integer);
            CREATE TABLE restrict (id integer);
            CREATE VIEW report AS SELECT id FROM first_table;
            DROP VIEW report;
            CREATE VIEW public.report AS SELECT id FROM first_table;
            ALTER TABLE report ALTER COLUMN id SET DEFAULT 1;
            DROP VIEW public.report;
            DROP TABLE first_table, cascade, restrict;
            CREATE TYPE public.mood AS ENUM ('a');
            DROP TYPE mood;
            SQL);

        $this->assertSame([], $schema->tables);
        $this->assertSame([], $schema->views);
        $this->assertSame([], $schema->enums);
        $this->assertTrue($schema->isComplete());
    }

    public function testDropsDoNotChooseBetweenAmbiguousSchemas(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE a.t (id integer);
            CREATE TABLE b.t (id integer);
            CREATE INDEX same_name ON a.t (id);
            CREATE INDEX same_name ON b.t (id);
            CREATE TYPE a.mood AS ENUM ('a');
            CREATE TYPE b.mood AS ENUM ('b');
            CREATE DOMAIN a.code AS text;
            CREATE DOMAIN b.code AS text;
            CREATE VIEW a.report AS SELECT 1;
            CREATE VIEW b.report AS SELECT 2;
            DROP TABLE t;
            DROP TYPE mood;
            DROP DOMAIN code;
            DROP VIEW report;
            DROP INDEX same_name;
            DROP INDEX a.same_name;
            SQL);

        $this->assertCount(5, $schema->issues);
        $this->assertCount(2, $schema->tables);
        $this->assertCount(2, $schema->enums);
        $this->assertCount(2, $schema->domains);
        $this->assertCount(2, $schema->views);
        $this->assertSame([], $schema->table('t', 'a')->indexes);
        $this->assertCount(1, $schema->table('t', 'b')->indexes);
    }

    public function testIssueLinesAdvanceAcrossMultipleDiagnosticsAndResetForEachParse(): void
    {
        $parser = new PostgresParser;
        $schema = $parser->parse(<<<'SQL'
            CREATE TABLE first () INHERITS (missing, also_missing);

            -- spacing and comments must remain in source offsets
            CREATE TABLE second () INHERITS (missing);
            SQL);

        $this->assertSame([1, 1], array_column($schema->table('first')->issues, 'line'));
        $this->assertSame([4], array_column($schema->table('second')->issues, 'line'));
        $next = $parser->parse("\nCREATE TABLE next () INHERITS (missing);");
        $this->assertSame([2], array_column($next->table('next')->issues, 'line'));
    }

    public function testUnsupportedOwnedFactsRemainScoped(): void
    {
        $schema = (new PostgresParser)->parse(<<<'SQL'
            CREATE TABLE typed OF public.external_type;
            CREATE TABLE inherited () INHERITS (missing_parent);
            CREATE TABLE normal (id integer, CONSTRAINT spatial EXCLUDE USING gist (id WITH =));
            CREATE TABLE complete (id integer);
            SQL);

        $this->assertSame([], $schema->issues);
        $this->assertFalse($schema->table('typed')->isComplete());
        $this->assertFalse($schema->table('inherited')->isComplete());
        $this->assertFalse($schema->table('normal')->isComplete());
        $this->assertNotNull($schema->table('normal')->column('id'));
        $this->assertTrue($schema->table('complete')->isComplete());
    }

    #[DataProvider('malformedSql')]
    public function testMalformedInputDoesNotContaminateTheNextParse(string $sql): void
    {
        $parser = new PostgresParser;
        try {
            $parser->parse($sql);
            $this->fail('Expected malformed SQL to fail.');
        } catch (ParseException) {
            $schema = $parser->parse('CREATE TABLE valid (id integer);');
            $this->assertSame(['valid'], array_column($schema->tables, 'name'));
            $this->assertTrue($schema->isComplete());
        }
    }

    /**
     * Provide malformed supported declarations and a non-text archive.
     *
     * @return list<array{string}>
     */
    public static function malformedSql(): array
    {
        return [
            ["PGDMP\0archive"],
            ['CREATE TABLE missing'],
            ['CREATE TABLE t (id integer'],
            ["CREATE TYPE mood AS ENUM ('unfinished);"],
            ['CREATE TABLE t (id integer); CREATE INDEX idx ON t'],
            ['CREATE DOMAIN first AS second; CREATE DOMAIN second AS first;'],
        ];
    }
}
