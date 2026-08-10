<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Closure;
use Exception;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use RuntimeException;

class DatabaseSchemaBlueprintTest extends SqliteTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', false);
    }

    protected function setUpInCoroutine(): void
    {
        // Purge and reconnect to apply the foreign_key_constraints config
        DB::purge();
        Schema::dropAllTables();
        $this->artisan('migrate:install');
    }

    public function testRenamingAndChangingColumnsWork()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name');
            $table->string('age');
        });

        $blueprint = $this->getBlueprint('SQLite', 'users', function ($table) {
            $table->renameColumn('name', 'first_name');
            $table->integer('age')->change();
        });

        $queries = $blueprint->toSql();

        $expected = [
            'alter table "users" rename column "name" to "first_name"',
            'create table "__temp__users" ("first_name" varchar not null, "age" integer not null)',
            'insert into "__temp__users" ("first_name", "age") select "first_name", "age" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
        ];

        $this->assertEquals($expected, $queries);
    }

    public function testRenamingColumnsWorks()
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->create('test', function (Blueprint $table) {
            $table->string('foo');
            $table->string('baz');
        });

        $schema->table('test', function (Blueprint $table) {
            $table->renameColumn('foo', 'bar');
            $table->renameColumn('baz', 'qux');
        });

        $this->assertFalse($schema->hasColumn('test', 'foo'));
        $this->assertFalse($schema->hasColumn('test', 'baz'));
        $this->assertTrue($schema->hasColumns('test', ['bar', 'qux']));
    }

    public function testNativeColumnModifyingOnPostgreSql(): void
    {
        $blueprint = $this->getBlueprint('Postgres', 'users', function ($table) {
            $table->integer('code')->autoIncrement()->from(10)->comment('my comment')->change();
        });

        $this->assertEquals([
            'alter table "users" '
            . 'alter column "code" type integer, '
            . 'alter column "code" set not null',
            'select setval(pg_get_serial_sequence(\'"users"\', \'code\'), 10, false)',
            'comment on column "users"."code" is \'my comment\'',
        ], $blueprint->toSql());

        $blueprint = $this->getBlueprint('Postgres', 'users', function ($table) {
            $table->char('name', 40)->nullable()->default('easy')->collation('unicode')->change();
        });

        $this->assertEquals([
            'alter table "users" '
            . 'alter column "name" type char(40) collate "unicode", '
            . 'alter column "name" drop not null, '
            . 'alter column "name" set default \'easy\', '
            . 'alter column "name" drop identity if exists',
            'comment on column "users"."name" is NULL',
        ], $blueprint->toSql());

        $blueprint = $this->getBlueprint('Postgres', 'users', function ($table) {
            $table->integer('foo')->generatedAs('expression')->always()->change();
        });

        $this->assertEquals([
            'alter table "users" '
            . 'alter column "foo" type integer, '
            . 'alter column "foo" set not null, '
            . 'alter column "foo" drop default, '
            . 'alter column "foo" drop identity if exists, '
            . 'alter column "foo" add  generated always as identity (expression)',
            'comment on column "users"."foo" is NULL',
        ], $blueprint->toSql());

        $blueprint = $this->getBlueprint('Postgres', 'users', function ($table) {
            $table->geometry('foo', 'point', 1234)->change();
        });

        $this->assertEquals([
            'alter table "users" '
            . 'alter column "foo" type geometry(point,1234), '
            . 'alter column "foo" set not null, '
            . 'alter column "foo" drop default, '
            . 'alter column "foo" drop identity if exists',
            'comment on column "users"."foo" is NULL',
        ], $blueprint->toSql());

        $blueprint = $this->getBlueprint('Postgres', 'users', function ($table) {
            $table->timestamp('added_at', 2)->useCurrent()->storedAs(null)->change();
        });

        $this->assertEquals([
            'alter table "users" '
            . 'alter column "added_at" type timestamp(2) without time zone, '
            . 'alter column "added_at" set not null, '
            . 'alter column "added_at" set default CURRENT_TIMESTAMP, '
            . 'alter column "added_at" drop expression if exists, '
            . 'alter column "added_at" drop identity if exists',
            'comment on column "users"."added_at" is NULL',
        ], $blueprint->toSql());
    }

    public function testChangingColumnWithCollationWorks()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('age');
        });

        $blueprint = $this->getBlueprint('SQLite', 'users', function ($table) {
            $table->integer('age')->collation('RTRIM')->change();
        });

        $blueprint2 = $this->getBlueprint('SQLite', 'users', function ($table) {
            $table->integer('age')->collation('NOCASE')->change();
        });

        $queries = $blueprint->toSql();

        $expected = [
            'create table "__temp__users" ("age" integer not null collate \'RTRIM\')',
            'insert into "__temp__users" ("age") select "age" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
        ];

        $this->assertEquals($expected, $queries);

        $queries = $blueprint2->toSql();

        $expected = [
            'create table "__temp__users" ("age" integer not null collate \'NOCASE\')',
            'insert into "__temp__users" ("age") select "age" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
        ];

        $this->assertEquals($expected, $queries);
    }

    public function testChangingCharColumnsWork()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name');
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->text('changed_col')->change();
            })->toSql();
        };

        $expected = [
            'create table "__temp__users" ("name" varchar not null)',
            'insert into "__temp__users" ("name") select "name" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));
    }

    public function testChangingPrimaryAutoincrementColumnsToNonAutoincrementColumnsWork()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->binary('id')->change();
            })->toSql();
        };

        $expected = [
            'create table "__temp__users" ("id" blob not null, primary key ("id"))',
            'insert into "__temp__users" ("id") select "id" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));
    }

    public function testChangingDoubleColumnsWork()
    {
        DB::connection()->getSchemaBuilder()->create('products', function ($table) {
            $table->integer('price');
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'products', function ($table) {
                $table->double('price')->change();
            })->toSql();
        };

        $expected = [
            'create table "__temp__products" ("price" double not null)',
            'insert into "__temp__products" ("price") select "price" from "products"',
            'drop table "products"',
            'alter table "__temp__products" rename to "products"',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));
    }

    public function testChangingColumnsWithDefaultWorks()
    {
        DB::connection()->getSchemaBuilder()->create('products', function ($table) {
            $table->integer('changed_col');
            $table->timestamp('timestamp_col')->useCurrent();
            $table->integer('integer_col')->default(123);
            $table->string('string_col')->default('value');
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'products', function ($table) {
                $table->text('changed_col')->change();
            })->toSql();
        };

        $expected = [
            'create table "__temp__products" ("changed_col" text not null, "timestamp_col" datetime not null default (CURRENT_TIMESTAMP), "integer_col" integer not null default (\'123\'), "string_col" varchar not null default (\'value\'))',
            'insert into "__temp__products" ("changed_col", "timestamp_col", "integer_col", "string_col") select "changed_col", "timestamp_col", "integer_col", "string_col" from "products"',
            'drop table "products"',
            'alter table "__temp__products" rename to "products"',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));
    }

    public function testRenameIndexWorks()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name');
            $table->string('age');
        });
        DB::connection()->getSchemaBuilder()->table('users', function ($table) {
            $table->index(['name'], 'index1');
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->renameIndex('index1', 'index2');
            })->toSql();
        };

        $expected = [
            'drop index "index1"',
            'create index "index2" on "users" ("name")',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));

        $expected = [
            'alter table `users` rename index `index1` to `index2`',
        ];

        $this->assertEquals($expected, $getSql('MySql'));

        $expected = [
            'alter index "index1" rename to "index2"',
        ];

        $this->assertEquals($expected, $getSql('Postgres'));
    }

    public function testRebuildPreservesExpressionPartialOrderedAndCollatedIndexes(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        $schema->create('items', function (Blueprint $table) {
            $table->integer('id');
            $table->string('email');
            $table->integer('score');
        });

        $indexSql = 'CREATE UNIQUE INDEX "MixedCase_Index" ON "items" ("email" COLLATE NOCASE DESC) WHERE "score" > 0';
        $connection->statement($indexSql);

        $schema->table('items', function (Blueprint $table) {
            $table->bigInteger('score')->change();
        });

        $this->assertSame($indexSql, $this->indexSql('MixedCase_Index'));
    }

    public function testRebuildQualifiesReplayedIndexesForAnAttachedSchema(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement("attach database ':memory:' as tenant");

        try {
            $schema->create('tenant.items', function (Blueprint $table) {
                $table->integer('id');
                $table->string('email');
            });
            $connection->statement(
                'CREATE INDEX tenant."Tenant_Items_Email" ON "items" ("email" COLLATE NOCASE DESC)'
            );

            $schema->table('tenant.items', function (Blueprint $table) {
                $table->text('email')->change();
            });

            $this->assertSame(
                'CREATE INDEX "Tenant_Items_Email" ON "items" ("email" COLLATE NOCASE DESC)',
                $connection->scalar(
                    "select sql from tenant.sqlite_schema where type = 'index' and name = 'Tenant_Items_Email'"
                )
            );
        } finally {
            $connection->statement('detach database tenant');
        }
    }

    public function testColumnRenameUpdatesOnlyExactIndexAndForeignKeyColumns(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        $schema->create('parents', function (Blueprint $table) {
            $table->integer('id')->primary();
        });
        $schema->create('children', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('user_id');
            $table->string('name');
            $table->index('id', 'children_id_index');
            $table->index('user_id', 'children_user_id_index');
            $table->foreign('user_id')->references('id')->on('parents');
        });

        $schema->table('children', function (Blueprint $table) {
            $table->renameColumn('id', 'uuid');
            $table->text('name')->change();
        });

        $this->assertTrue($schema->hasIndex('children', ['uuid']));
        $this->assertTrue($schema->hasIndex('children', ['user_id']));
        $this->assertSame(
            ['user_id'],
            $schema->getForeignKeys('children')[0]['columns'],
        );
    }

    public function testRebuildReemitsInlineUniqueConstraints(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (id integer, email varchar not null unique, name varchar not null)'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $connection->statement("insert into contacts (id, email, name) values (1, 'one@example.com', 'One')");

        try {
            $connection->statement("insert into contacts (id, email, name) values (2, 'one@example.com', 'Two')");
            $this->fail('Expected the rebuilt unique constraint to reject a duplicate value.');
        } catch (QueryException) {
        }
    }

    public function testRebuildPreservesUniqueConstraintCollation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text, name varchar, unique (email collate nocase))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $connection->statement("insert into contacts (email, name) values ('A', 'One')");

        try {
            $connection->statement("insert into contacts (email, name) values ('a', 'Two')");
            $this->fail('Expected the rebuilt collated unique constraint to reject a duplicate value.');
        } catch (QueryException) {
        }
    }

    public function testRebuildPreservesUniqueConstraintSortOrder(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text, name varchar, unique (email desc))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $this->assertSame(
            1,
            (int) $connection->scalar(
                'select "desc" from pragma_index_xinfo(\'sqlite_autoindex_contacts_1\') where "key" = 1'
            ),
        );
    }

    public function testRebuildPreservesUniqueConstraintInheritedColumnCollation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text collate nocase, name varchar, unique (email))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $connection->statement("insert into contacts (email, name) values ('A', 'One')");

        try {
            $connection->statement("insert into contacts (email, name) values ('a', 'Two')");
            $this->fail('Expected the rebuilt inherited collation to reject a duplicate value.');
        } catch (QueryException) {
        }
    }

    public function testRebuildPreservesExplicitBinaryUniqueConstraintCollation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text collate nocase, name varchar, unique (email collate binary))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $connection->statement("insert into contacts (email, name) values ('A', 'One')");
        $connection->statement("insert into contacts (email, name) values ('a', 'Two')");

        $this->assertSame(2, $connection->table('contacts')->count());
    }

    public function testRebuildPreservesPrimaryKeyCollationAndSortOrder(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text, name varchar, primary key (email collate nocase desc))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $this->assertSame(
            1,
            (int) $connection->scalar(
                'select "desc" from pragma_index_xinfo(\'sqlite_autoindex_contacts_1\') where "key" = 1'
            ),
        );

        $connection->statement("insert into contacts (email, name) values ('A', 'One')");

        try {
            $connection->statement("insert into contacts (email, name) values ('a', 'Two')");
            $this->fail('Expected the rebuilt collated primary key to reject a duplicate value.');
        } catch (QueryException) {
        }
    }

    public function testRebuildPreservesExplicitBinaryPrimaryKeyCollation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text collate nocase, name varchar, primary key (email collate binary))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $connection->statement("insert into contacts (email, name) values ('A', 'One')");
        $connection->statement("insert into contacts (email, name) values ('a', 'Two')");

        $this->assertSame(2, $connection->table('contacts')->count());
    }

    public function testRebuildPreservesCommaBearingColumnNames(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts ("email,address" text, name varchar, unique ("email,address"))'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $connection->statement(
            'insert into contacts ("email,address", name) values (\'one@example.com\', \'One\')'
        );

        try {
            $connection->statement(
                'insert into contacts ("email,address", name) values (\'one@example.com\', \'Two\')'
            );
            $this->fail('Expected the rebuilt comma-bearing unique constraint to reject a duplicate value.');
        } catch (QueryException) {
        }
    }

    public function testRebuildPreservesWithoutRowid(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text primary key, name varchar) without rowid'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $tableSql = $connection->scalar(
            "select sql from sqlite_master where type = 'table' and name = 'contacts'"
        );
        $this->assertIsString($tableSql);
        $this->assertMatchesRegularExpression('/\bwithout\s+rowid\s*$/i', $tableSql);

        try {
            $connection->statement("insert into contacts (email, name) values (null, 'One')");
            $this->fail('Expected the rebuilt WITHOUT ROWID primary key to reject null.');
        } catch (QueryException) {
        }
    }

    #[RequiresDatabase('sqlite', '>=3.37.0')]
    public function testRebuildPreservesStrictTables(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (score integer, name text) strict'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $this->assertSame(
            1,
            (int) $connection->scalar("select strict from pragma_table_list where name = 'contacts'"),
        );

        try {
            $connection->statement("insert into contacts (score, name) values ('not an integer', 'One')");
            $this->fail('Expected the rebuilt STRICT table to reject an invalid integer.');
        } catch (QueryException) {
        }
    }

    public function testRebuildRejectsCheckConstraintsBeforeMutation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (score integer check (score > 0), name varchar)'
        );
        $exception = null;

        try {
            $schema->table('contacts', function (Blueprint $table) {
                $table->text('name')->change();
            });
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        if ($exception === null) {
            $this->fail('Expected the CHECK constraint to prevent the rebuild.');
        }

        $this->assertStringContainsString('CHECK constraint', $exception->getMessage());
        $this->assertSame('varchar', $schema->getColumnType('contacts', 'name'));

        try {
            $connection->statement("insert into contacts (score, name) values (-1, 'One')");
            $this->fail('Expected the original CHECK constraint to remain enforced.');
        } catch (QueryException) {
        }
    }

    public function testRebuildRejectsBehaviorChangingConflictClausesBeforeMutation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email text unique on conflict replace, name varchar)'
        );
        $exception = null;

        try {
            $schema->table('contacts', function (Blueprint $table) {
                $table->text('name')->change();
            });
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        if ($exception === null) {
            $this->fail('Expected the conflict clause to prevent the rebuild.');
        }

        $this->assertStringContainsString('ON CONFLICT clause', $exception->getMessage());
        $this->assertSame('varchar', $schema->getColumnType('contacts', 'name'));
        $connection->statement("insert into contacts (email, name) values ('one@example.com', 'One')");
        $connection->statement("insert into contacts (email, name) values ('one@example.com', 'Two')");
        $this->assertSame('Two', $connection->table('contacts')->value('name'));
    }

    public function testRebuildRejectsDeferredForeignKeysBeforeMutation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement('create table parents (id integer primary key)');
        $connection->statement(
            'create table contacts (parent_id integer references parents (id) deferrable initially deferred, name varchar)'
        );
        $exception = null;

        try {
            $schema->table('contacts', function (Blueprint $table) {
                $table->text('name')->change();
            });
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        if ($exception === null) {
            $this->fail('Expected the deferred foreign key to prevent the rebuild.');
        }

        $this->assertStringContainsString('DEFERRABLE INITIALLY DEFERRED clause', $exception->getMessage());
        $this->assertSame('varchar', $schema->getColumnType('contacts', 'name'));
    }

    public function testRebuildAllowsBehaviorEquivalentConflictAndForeignKeyClauses(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement('create table parents (id integer primary key)');
        $connection->statement(
            'create table abort_contacts (email text unique on conflict abort, name varchar)'
        );
        $connection->statement(
            'create table immediate_contacts (parent_id integer references parents (id) deferrable initially immediate, name varchar)'
        );
        $connection->statement(
            'create table nondeferrable_contacts (parent_id integer references parents (id) not deferrable initially deferred, name varchar)'
        );

        foreach (['abort_contacts', 'immediate_contacts', 'nondeferrable_contacts'] as $table) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->text('name')->change();
            });

            $this->assertSame('text', $schema->getColumnType($table, 'name'));
        }
    }

    public function testRebuildIgnoresGuardTokensInsideQuotedAndCommentedText(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(<<<'SQL'
create table contacts (
    "deferrable" text default 'on conflict replace',
    note text default 'check(',
    name varchar /* check (ignored) */
)
SQL);

        $schema->table('contacts', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $this->assertSame('text', $schema->getColumnType('contacts', 'name'));
    }

    public function testFailedLateStatementRollsBackTheCompleteRebuild(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        $schema->create('users', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
        });
        $connection->table('users')->insert(['id' => 1, 'name' => 'Taylor']);

        try {
            $schema->table('users', function (Blueprint $table) {
                $table->text('name')->change();
                $table->rawIndex('(', 'invalid_index');
            });
            $this->fail('Expected the invalid index to fail.');
        } catch (QueryException) {
        }

        $this->assertSame(['id', 'name'], $schema->getColumnListing('users'));
        $this->assertSame(
            ['id' => 1, 'name' => 'Taylor'],
            (array) $connection->table('users')->first(),
        );
    }

    public function testRebuildPreservesDisabledForeignKeyState(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
        });

        $schema->table('users', function (Blueprint $table) {
            $table->text('name')->change();
        });

        $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));
    }

    public function testAddingACompositeForeignKeyPreservesEnabledStateIndexesAndRows(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('parents', function (Blueprint $table) {
            $table->integer('tenant_id');
            $table->integer('id');
            $table->unique(['tenant_id', 'id']);
        });
        $schema->create('children', function (Blueprint $table) {
            $table->integer('tenant_id');
            $table->integer('parent_id');
            $table->string('name');
            $table->index(['tenant_id', 'parent_id'], 'children_parent_index');
        });
        $connection->table('parents')->insert(['tenant_id' => 1, 'id' => 10]);
        $connection->table('children')->insert([
            'tenant_id' => 1,
            'parent_id' => 10,
            'name' => 'Taylor',
        ]);
        $schema->enableForeignKeyConstraints();

        $schema->table('children', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'id'])
                ->on('parents');
        });

        $foreignKey = $schema->getForeignKeys('children')[0];

        $this->assertSame(1, (int) $connection->scalar('pragma foreign_keys'));
        $this->assertSame(['tenant_id', 'parent_id'], $foreignKey['columns']);
        $this->assertSame(['tenant_id', 'id'], $foreignKey['foreign_columns']);
        $this->assertSame('parents', $foreignKey['foreign_table']);
        $this->assertTrue($schema->hasIndex('children', 'children_parent_index'));
        $this->assertSame('Taylor', $connection->table('children')->value('name'));
    }

    public function testEmptyRebuildUsesASavepointInsideATransactionWithForeignKeysEnabled(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
        });
        $schema->enableForeignKeyConstraints();

        $connection->transaction(function () use ($connection, $schema) {
            $schema->table('users', function (Blueprint $table) {
                $table->text('name')->change();
            });

            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame('text', $schema->getColumnType('users', 'name'));
        });

        $this->assertSame(1, (int) $connection->scalar('pragma foreign_keys'));
    }

    public function testPopulatedRebuildFailsBeforeMutationInsideATransactionWithForeignKeysEnabled(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
        });
        $connection->table('users')->insert(['id' => 1, 'name' => 'Taylor']);
        $schema->enableForeignKeyConstraints();

        try {
            $connection->transaction(function () use ($schema) {
                $schema->table('users', function (Blueprint $table) {
                    $table->text('name')->change();
                });
            });
            $this->fail('Expected the populated rebuild to fail before mutation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('populated table [users]', $exception->getMessage());
        }

        $this->assertSame(1, (int) $connection->scalar('pragma foreign_keys'));
        $this->assertSame('varchar', $schema->getColumnType('users', 'name'));
        $this->assertSame('Taylor', $connection->table('users')->value('name'));
    }

    public function testRenameIndexPreservesItsStoredDefinitionAndPhysicalCase(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('items', function (Blueprint $table) {
            $table->string('email');
            $table->integer('score');
        });
        $connection->statement(
            'CREATE UNIQUE INDEX "MixedCase_Index" ON "items" ("email" COLLATE NOCASE DESC) WHERE "score" > 0'
        );

        $schema->table('items', function (Blueprint $table) {
            $table->renameIndex('mixedcase_index', 'Renamed_Index');
        });

        $this->assertSame(
            'CREATE UNIQUE INDEX "Renamed_Index" ON "items" ("email" COLLATE NOCASE DESC) WHERE "score" > 0',
            $this->indexSql('Renamed_Index'),
        );
    }

    public function testRenameIndexPreservesExplicitBinaryCollation(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement('create table contacts (email text collate nocase, name varchar)');
        $connection->statement('create unique index contacts_email_unique on contacts (email collate binary)');

        $schema->table('contacts', function (Blueprint $table) {
            $table->renameIndex('contacts_email_unique', 'renamed_email_unique');
        });

        $connection->statement("insert into contacts (email, name) values ('A', 'One')");
        $connection->statement("insert into contacts (email, name) values ('a', 'Two')");

        $this->assertSame(2, $connection->table('contacts')->count());
    }

    public function testRenameIndexRejectsConstraintBackedIndexesBeforeExecution(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement('create table contacts (email varchar not null unique, name varchar not null)');

        try {
            $schema->table('contacts', function (Blueprint $table) {
                $table->renameIndex('SQLITE_AUTOINDEX_CONTACTS_1', 'renamed_unique');
            });
            $this->fail('Expected the constraint-backed index rename to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('backs a unique constraint', $exception->getMessage());
        }

        $this->assertTrue($schema->hasIndex('contacts', ['email'], 'unique'));
    }

    public function testRichIndexRejectsStaleReplayWhenRenamePrecedesRebuild(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('items', function (Blueprint $table) {
            $table->string('name');
            $table->string('email');
            $table->integer('score');
        });
        $connection->statement('CREATE INDEX "EmailExpr" ON "items" (lower("email"))');
        $connection->table('items')->insert(['name' => 'Taylor', 'email' => 'taylor@example.com', 'score' => 1]);

        try {
            $schema->table('items', function (Blueprint $table) {
                $table->renameColumn('name', 'label');
                $table->bigInteger('score')->change();
            });
            $this->fail('Expected the stale rich-index replay to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('EmailExpr', $exception->getMessage());
            $this->assertStringContainsString('Move the rename after', $exception->getMessage());
        }

        $this->assertSame(['name', 'email', 'score'], $schema->getColumnListing('items'));
        $this->assertSame('CREATE INDEX "EmailExpr" ON "items" (lower("email"))', $this->indexSql('EmailExpr'));
        $this->assertSame('Taylor', $connection->table('items')->value('name'));
    }

    public function testRichIndexUsesNativeRewriteWhenRenameFollowsRebuild(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('items', function (Blueprint $table) {
            $table->string('name');
            $table->integer('score');
        });
        $connection->statement('CREATE INDEX "NameExpr" ON "items" (lower("name"))');

        $schema->table('items', function (Blueprint $table) {
            $table->bigInteger('score')->change();
            $table->renameColumn('name', 'label');
        });

        $this->assertSame(['label', 'score'], $schema->getColumnListing('items'));
        $this->assertSame('CREATE INDEX "NameExpr" ON "items" (lower("label"))', $this->indexSql('NameExpr'));
    }

    public function testRenamedUniqueConstraintIsReemittedInline(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $connection->statement(
            'create table contacts (email varchar not null unique, name varchar not null)'
        );

        $schema->table('contacts', function (Blueprint $table) {
            $table->renameColumn('email', 'address');
            $table->text('name')->change();
        });

        $connection->table('contacts')->insert(['address' => 'one@example.com', 'name' => 'One']);

        try {
            $connection->table('contacts')->insert(['address' => 'one@example.com', 'name' => 'Two']);
            $this->fail('Expected the rebuilt unique constraint to reject a duplicate value.');
        } catch (QueryException) {
        }
    }

    public function testSQLiteDoubleQuotedStringFallbackChangesUniqueIndexSemantics(): void
    {
        $connection = DB::connection();

        $connection->statement('create table dqs_probe (label varchar not null)');

        try {
            $connection->statement(
                'create index dqs_probe_index on dqs_probe ("missing") where "gone" is not null'
            );
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'no such column:')) {
                throw $exception;
            }

            $this->markTestSkipped('SQLite double-quoted string fallback is disabled for DDL.');
        } finally {
            $connection->statement('drop table dqs_probe');
        }

        $connection->statement('create table expression_case (label varchar not null, active integer not null)');
        $connection->statement(
            'create unique index expression_case_unique on expression_case ("name") where "active" = 1'
        );
        $connection->table('expression_case')->insert(['label' => 'First', 'active' => 1]);

        try {
            $connection->table('expression_case')->insert(['label' => 'Second', 'active' => 1]);
            $this->fail('Expected the constant-expression unique index to admit only one matching row.');
        } catch (QueryException) {
        }

        $connection->statement('create table predicate_case (name varchar not null, active integer not null)');
        $connection->statement(
            'create unique index predicate_case_unique on predicate_case ("name") where "gone" is not null'
        );
        $connection->table('predicate_case')->insert(['name' => 'Taylor', 'active' => 0]);

        try {
            $connection->table('predicate_case')->insert(['name' => 'Taylor', 'active' => 0]);
            $this->fail('Expected the degraded partial predicate to reject the duplicate value.');
        } catch (QueryException) {
        }

        $this->assertSame(-2, (int) $connection->scalar(
            'select cid from pragma_index_xinfo("expression_case_unique") where key = 1'
        ));
    }

    public function testNativeDropRejectsRichIndexDependencies(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        foreach (['expression', 'predicate'] as $case) {
            $table = $case . '_case';
            $schema->create($table, function (Blueprint $table) {
                $table->string('name');
                $table->string('note');
                $table->integer('score');
            });
            $connection->statement(match ($case) {
                'expression' => 'create index expression_index on expression_case (lower("note"))',
                'predicate' => 'create index predicate_index on predicate_case ("name") where "note" is not null',
            });

            try {
                $schema->table($table, function (Blueprint $table) {
                    $table->dropColumn('note');
                    $table->bigInteger('score')->change();
                });
                $this->fail("Expected SQLite to reject the dependent {$case} index.");
            } catch (QueryException) {
            }

            $this->assertSame(['name', 'note', 'score'], $schema->getColumnListing($table));
        }
    }

    public function testNativeDropAllowsAnUnrelatedRichIndexInEitherCommandOrder(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();

        foreach (['drop_first', 'rebuild_first'] as $case) {
            $schema->create($case, function (Blueprint $table) {
                $table->string('name');
                $table->string('note');
                $table->integer('score');
            });
            $index = $case . '_name_expression';
            $indexSql = "CREATE INDEX \"{$index}\" ON \"{$case}\" (lower(\"name\"))";
            $connection->statement($indexSql);

            $schema->table($case, function (Blueprint $table) use ($case) {
                if ($case === 'drop_first') {
                    $table->dropColumn('note');
                    $table->bigInteger('score')->change();
                } else {
                    $table->bigInteger('score')->change();
                    $table->dropColumn('note');
                }
            });

            $this->assertSame(['name', 'score'], $schema->getColumnListing($case));
            $this->assertSame($indexSql, $this->indexSql($index));
        }
    }

    public function testNewRawIndexBeforeRenameFailsWithoutATypeError(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('items', function (Blueprint $table) {
            $table->string('name');
            $table->string('email');
            $table->integer('score');
        });

        try {
            $schema->table('items', function (Blueprint $table) {
                $table->rawIndex('lower("email")', 'email_expression');
                $table->renameColumn('name', 'label');
                $table->bigInteger('score')->change();
            });
            $this->fail('Expected the raw index replay to fail safely.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('email_expression', $exception->getMessage());
        }

        $this->assertSame(['name', 'email', 'score'], $schema->getColumnListing('items'));
        $this->assertNull($this->indexSql('email_expression'));
    }

    public function testAddUniqueIndexWithoutNameWorks()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name')->nullable();
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->string('name')->nullable()->unique()->change();
            })->toSql();
        };

        $expected = [
            'alter table `users` modify `name` varchar(255) null',
            'alter table `users` add unique `users_name_unique`(`name`)',
        ];

        $this->assertEquals($expected, $getSql('MySql'));

        $expected = [
            'alter table "users" alter column "name" type varchar(255), alter column "name" drop not null, alter column "name" drop default, alter column "name" drop identity if exists',
            'alter table "users" add constraint "users_name_unique" unique ("name")',
            'comment on column "users"."name" is NULL',
        ];

        $this->assertEquals($expected, $getSql('Postgres'));

        $expected = [
            'create table "__temp__users" ("name" varchar)',
            'insert into "__temp__users" ("name") select "name" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
            'create unique index "users_name_unique" on "users" ("name")',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));
    }

    public function testAddUniqueIndexWithNameWorks()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name')->nullable();
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->unsignedInteger('name')->nullable()->unique('index1')->change();
            })->toSql();
        };

        $expected = [
            'alter table `users` modify `name` int unsigned null',
            'alter table `users` add unique `index1`(`name`)',
        ];

        $this->assertEquals($expected, $getSql('MySql'));

        $expected = [
            'alter table "users" alter column "name" type integer, alter column "name" drop not null, alter column "name" drop default, alter column "name" drop identity if exists',
            'alter table "users" add constraint "index1" unique ("name")',
            'comment on column "users"."name" is NULL',
        ];

        $this->assertEquals($expected, $getSql('Postgres'));

        $expected = [
            'create table "__temp__users" ("name" integer)',
            'insert into "__temp__users" ("name") select "name" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
            'create unique index "index1" on "users" ("name")',
        ];

        $this->assertEquals($expected, $getSql('SQLite'));
    }

    public function testAddingPrimaryAndUniqueIndexesUsesOneSqliteRebuild(): void
    {
        DB::connection()->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->string('name')->nullable();
        });

        $blueprint = $this->getBlueprint('SQLite', 'users', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('email')->unique();
            $table->text('name')->nullable()->change();
        });

        $this->assertSame([
            'alter table "users" add column "key" varchar not null',
            'alter table "users" add column "email" varchar not null',
            'create table "__temp__users" ("name" text, "key" varchar not null, "email" varchar not null, primary key ("key"))',
            'insert into "__temp__users" ("name", "key", "email") select "name", "key", "email" from "users"',
            'drop table "users"',
            'alter table "__temp__users" rename to "users"',
            'create unique index "users_email_unique" on "users" ("email")',
        ], $blueprint->toSql());
    }

    public function testAddColumnNamedCreateWorks()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('create')->nullable();
        });

        $this->assertTrue(Schema::hasColumn('users', 'create'));
    }

    public function testDropIndexOnColumnChangeWorks()
    {
        DB::connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->string('name')->nullable();
        });

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->string('name')->nullable()->unique(false)->change();
            })->toSql();
        };

        $this->assertContains(
            'alter table `users` drop index `users_name_unique`',
            $getSql('MySql'),
        );

        $this->assertContains(
            'alter table "users" drop constraint "users_name_unique"',
            $getSql('Postgres'),
        );

        $this->assertContains(
            'drop index "users_name_unique"',
            $getSql('SQLite'),
        );
    }

    public function testItDoesNotSetPrecisionHigherThanSupportedWhenRenamingTimestamps()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->timestamp('created_at');
        });

        try {
            // this would only fail in mysql, postgres and sql server
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('created_at', 'new_created_at');
            });

            $this->addToAssertionCount(1); // it did not throw
        } catch (Exception $e) {
            // Expecting something similar to:
            // Illuminate\Database\QueryException
            //   SQLSTATE[42000]: Syntax error or access violation: 1426 Too big precision 10 specified for 'my_timestamp'. Maximum is 6....
            $this->fail('test_it_does_not_set_precision_higher_than_supported_when_renaming_timestamps has failed. Error: ' . $e->getMessage());
        }
    }

    public function testItEnsuresDroppingForeignKeyIsAvailable()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This database driver does not support dropping foreign keys by name.');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('something');
        });
    }

    protected function getBlueprint(
        string $grammar,
        string $table,
        Closure $callback,
    ): Blueprint {
        $grammarClass = 'Hypervel\Database\Schema\Grammars\\' . $grammar . 'Grammar';

        $connection = DB::connection();
        $connection->setSchemaGrammar(new $grammarClass($connection));

        return new Blueprint($connection, $table, $callback);
    }

    /**
     * Get the stored SQL for the given main-schema index.
     */
    protected function indexSql(string $index): ?string
    {
        return DB::connection()->scalar(
            "select sql from sqlite_schema where type = 'index' and name = ?",
            [$index],
        );
    }
}
