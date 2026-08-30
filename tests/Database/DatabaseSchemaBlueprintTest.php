<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Database\Schema\ForeignKeyDefinition;
use Hypervel\Database\Schema\Grammars\MySqlGrammar;
use Hypervel\Database\Schema\IndexDefinition;
use Hypervel\Support\Fluent;
use Hypervel\Tests\Database\Fixtures\Models\User;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSchemaBlueprintTest extends TestCase
{
    protected function tearDown(): void
    {
        Builder::$defaultMorphKeyType = 'int';

        parent::tearDown();
    }

    public function testBuildDelegatesToTheConnectionOwnedBuilder(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new MySqlGrammar($connection));
        $builder = m::mock(Builder::class);
        $blueprint = new Blueprint($connection, 'users');

        $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($builder);
        $builder->shouldReceive('executeBlueprint')->once()->with($blueprint);

        $blueprint->build();
    }

    public function testIndexDefaultNames()
    {
        $blueprint = $this->getBlueprint(table: 'users');
        $blueprint->unique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_bar_unique', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'users');
        $blueprint->index('foo');
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_index', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'geo');
        $blueprint->spatialIndex('coordinates');
        $commands = $blueprint->getCommands();
        $this->assertSame('geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testIndexMethodsReturnIndexDefinitions(): void
    {
        $blueprint = $this->getBlueprint(table: 'users');

        $this->assertInstanceOf(IndexDefinition::class, $blueprint->primary('id'));
        $this->assertInstanceOf(IndexDefinition::class, $blueprint->unique('email'));
        $this->assertInstanceOf(IndexDefinition::class, $blueprint->index('name'));
        $this->assertInstanceOf(IndexDefinition::class, $blueprint->fullText('bio'));
        $this->assertInstanceOf(IndexDefinition::class, $blueprint->spatialIndex('location'));
        $this->assertInstanceOf(IndexDefinition::class, $blueprint->vectorIndex('embedding'));
        $this->assertInstanceOf(IndexDefinition::class, $blueprint->rawIndex('(lower(email))', 'users_email_lower_index'));
    }

    public function testOrdinaryIndexesRetainTheWhereNotNullModifier(): void
    {
        $blueprint = $this->getBlueprint(table: 'users');

        $index = $blueprint->index('email')->whereNotNull('email');
        $rawIndex = $blueprint->rawIndex('(lower(email))', 'users_email_lower_index')
            ->whereNotNull('email');

        $this->assertSame('email', $index->get('whereNotNull'));
        $this->assertSame('email', $rawIndex->get('whereNotNull'));

        $index->whereNotNull('verified_at');

        $this->assertSame('verified_at', $index->get('whereNotNull'));
    }

    #[DataProvider('nonOrdinaryIndexProvider')]
    public function testWhereNotNullRejectsNonOrdinaryIndexes(string $method, string $column): void
    {
        $blueprint = $this->getBlueprint(table: 'users');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The [whereNotNull] modifier is only available for ordinary indexes.',
        );

        $blueprint->{$method}($column)->whereNotNull('email');
    }

    public static function nonOrdinaryIndexProvider(): array
    {
        return [
            'primary' => ['primary', 'id'],
            'unique' => ['unique', 'email'],
            'full text' => ['fullText', 'bio'],
            'spatial' => ['spatialIndex', 'location'],
            'vector' => ['vectorIndex', 'embedding'],
        ];
    }

    public function testForeignReplacesTheTemporaryIndexDefinition(): void
    {
        $blueprint = $this->getBlueprint(table: 'users');

        $foreign = $blueprint->foreign('team_id');

        $this->assertInstanceOf(ForeignKeyDefinition::class, $foreign);
        $this->assertSame([$foreign], $blueprint->getCommands());
    }

    public function testIndexDefaultNamesWhenPrefixSupplied()
    {
        $blueprint = $this->getBlueprint(table: 'users', prefix: 'prefix_');
        $blueprint->unique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_bar_unique', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'users', prefix: 'prefix_');
        $blueprint->index('foo');
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_index', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'geo', prefix: 'prefix_');
        $blueprint->spatialIndex('coordinates');
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testDropIndexDefaultNames()
    {
        $blueprint = $this->getBlueprint(table: 'users');
        $blueprint->dropUnique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_bar_unique', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'users');
        $blueprint->dropIndex(['foo']);
        $commands = $blueprint->getCommands();
        $this->assertSame('users_foo_index', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'geo');
        $blueprint->dropSpatialIndex(['coordinates']);
        $commands = $blueprint->getCommands();
        $this->assertSame('geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testDropForeignPreservesColumnsWithAnExplicitName(): void
    {
        $blueprint = $this->getBlueprint(table: 'children');
        $blueprint->dropForeign(
            ['tenant_id', 'parent_id'],
            'children_parent_fk',
        );

        $command = $blueprint->getCommands()[0];

        $this->assertSame(['tenant_id', 'parent_id'], $command->columns);
        $this->assertSame('children_parent_fk', $command->index);
    }

    public function testDropForeignRejectsTwoConstraintNames(): void
    {
        $blueprint = $this->getBlueprint(table: 'children');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot drop foreign key [children_first_fk] with a second constraint name [children_second_fk]. Pass the foreign key columns as the first argument when specifying its name.',
        );

        $blueprint->dropForeign(
            'children_first_fk',
            'children_second_fk',
        );
    }

    public function testIndexCommandsPreserveExplicitIndexNameZero(): void
    {
        $blueprint = $this->getBlueprint(table: 'users');
        $blueprint->index('email', '0');

        $this->assertSame('0', $blueprint->getCommands()[0]->index);

        $blueprint = $this->getBlueprint(table: 'users');
        $blueprint->dropMorphs('imageable', '0');

        $this->assertSame('0', $blueprint->getCommands()[0]->index);
    }

    public function testDropIndexDefaultNamesWhenPrefixSupplied()
    {
        $blueprint = $this->getBlueprint(table: 'users', prefix: 'prefix_');
        $blueprint->dropUnique(['foo', 'bar']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_bar_unique', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'users', prefix: 'prefix_');
        $blueprint->dropIndex(['foo']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_users_foo_index', $commands[0]->index);

        $blueprint = $this->getBlueprint(table: 'geo', prefix: 'prefix_');
        $blueprint->dropSpatialIndex(['coordinates']);
        $commands = $blueprint->getCommands();
        $this->assertSame('prefix_geo_coordinates_spatialindex', $commands[0]->index);
    }

    public function testDefaultCurrentDate()
    {
        $getSql = function ($grammar, $mysql57 = false) {
            if ($grammar == 'MySql') {
                $connection = $this->getConnection($grammar);
                $mysql57 ? $connection->shouldReceive('getServerVersion')->andReturn('5.7') : $connection->shouldReceive('getServerVersion')->andReturn('8.0.13');
                $connection->shouldReceive('isMaria')->andReturn(false);

                return (new Blueprint($connection, 'users', function ($table) {
                    $table->date('created')->useCurrent();
                }))->toSql();
            } else {
                return $this->getBlueprint($grammar, 'users', function ($table) {
                    $table->date('created')->useCurrent();
                })->toSql();
            }
        };

        $this->assertEquals(['alter table `users` add `created` date not null default (CURDATE())'], $getSql('MySql'));
        $this->assertEquals(['alter table `users` add `created` date not null'], $getSql('MySql', mysql57: true));
        $this->assertEquals(['alter table "users" add column "created" date not null default CURRENT_DATE'], $getSql('Postgres'));
        $this->assertEquals(['alter table "users" add column "created" date not null default CURRENT_DATE'], $getSql('SQLite'));
    }

    public function testDefaultCurrentDateTime()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->dateTime('created')->useCurrent();
            })->toSql();
        };

        $this->assertEquals(['alter table `users` add `created` datetime not null default CURRENT_TIMESTAMP'], $getSql('MySql'));
        $this->assertEquals(['alter table "users" add column "created" timestamp(0) without time zone not null default CURRENT_TIMESTAMP'], $getSql('Postgres'));
        $this->assertEquals(['alter table "users" add column "created" datetime not null default CURRENT_TIMESTAMP'], $getSql('SQLite'));
    }

    public function testDefaultCurrentTimestamp()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->timestamp('created')->useCurrent();
            })->toSql();
        };

        $this->assertEquals(['alter table `users` add `created` timestamp not null default CURRENT_TIMESTAMP'], $getSql('MySql'));
        $this->assertEquals(['alter table "users" add column "created" timestamp(0) without time zone not null default CURRENT_TIMESTAMP'], $getSql('Postgres'));
        $this->assertEquals(['alter table "users" add column "created" datetime not null default CURRENT_TIMESTAMP'], $getSql('SQLite'));
    }

    public function testDefaultCurrentYear()
    {
        $getSql = function ($grammar, $mysql57 = false) {
            if ($grammar == 'MySql') {
                $connection = $this->getConnection($grammar);
                $mysql57 ? $connection->shouldReceive('getServerVersion')->andReturn('5.7') : $connection->shouldReceive('getServerVersion')->andReturn('8.0.13');
                $connection->shouldReceive('isMaria')->andReturn(false);

                return (new Blueprint($connection, 'users', function ($table) {
                    $table->year('birth_year')->useCurrent();
                }))->toSql();
            } else {
                return $this->getBlueprint($grammar, 'users', function ($table) {
                    $table->year('birth_year')->useCurrent();
                })->toSql();
            }
        };

        $this->assertEquals(['alter table `users` add `birth_year` year not null default (YEAR(CURDATE()))'], $getSql('MySql'));
        $this->assertEquals(['alter table `users` add `birth_year` year not null'], $getSql('MySql', mysql57: true));
        $this->assertEquals(['alter table "users" add column "birth_year" integer not null default EXTRACT(YEAR FROM CURRENT_DATE)'], $getSql('Postgres'));
        $this->assertEquals(['alter table "users" add column "birth_year" integer not null default (CAST(strftime(\'%Y\', \'now\') AS INTEGER))'], $getSql('SQLite'));
    }

    public function testRemoveColumn()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->string('foo');
                $table->string('remove_this');
                $table->removeColumn('remove_this');
            })->toSql();
        };

        $this->assertEquals(['alter table `users` add `foo` varchar(255) not null'], $getSql('MySql'));
    }

    public function testRemoveColumnMatchesNumericLookingNamesStrictly(): void
    {
        $blueprint = $this->getBlueprint('MySql', 'users', function (Blueprint $table) {
            $table->string('1e2');
            $table->string('0100');
            $table->string('100');
            $table->removeColumn('100');
        });

        $this->assertSame(['1e2', '0100'], array_column($blueprint->getColumns(), 'name'));
        $this->assertSame(['1e2', '0100'], array_column($blueprint->getCommands(), 'name'));
    }

    public function testCreatePromotesForeignKeyTargetsWithoutMovingOtherCommands(): void
    {
        $blueprint = new DatabaseSchemaBlueprintOrderingTestBlueprint(
            $this->getConnection('Postgres'),
            'nodes',
            function (DatabaseSchemaBlueprintOrderingTestBlueprint $table) {
                $table->create();
                $table->integer('parent_id');
                $table->foreign('parent_id')->references('id')->on('nodes');
                $table->unique('code');
                $table->integer('root_id');
                $table->foreign('root_id')->references('id')->on('nodes');
                $table->index('parent_id');
                $table->integer('id')->primary();
                $table->customCommand();
            },
        );

        $blueprint->toSql();

        $this->assertSame(
            [
                'create', 'unique', 'index', 'primary', 'foreign', 'foreign', 'custom',
                'AutoIncrementStartingValues', 'Comment', 'AutoIncrementStartingValues', 'Comment',
                'AutoIncrementStartingValues', 'Comment',
            ],
            array_column($blueprint->getCommands(), 'name'),
        );
        $this->assertSame($blueprint->generatedUniqueCommand, $blueprint->getCommands()[1]);
        $this->assertSame('preserved', $blueprint->getCommands()[1]->testMetadata);
    }

    public function testCreateKeepsTargetsAlreadyBeforeTheFirstForeignKeyInPlace(): void
    {
        $blueprint = $this->getBlueprint('Postgres', 'nodes', function (Blueprint $table) {
            $table->create();
            $table->unique('code');
            $table->foreign('parent_id')->references('id')->on('nodes');
            $table->index('parent_id');
        });
        $unique = $blueprint->getCommands()[1];

        $blueprint->toSql();

        $this->assertSame(['create', 'unique', 'index', 'foreign'], array_column($blueprint->getCommands(), 'name'));
        $this->assertSame($unique, $blueprint->getCommands()[1]);
    }

    public function testAlterPlacesGeneratedForeignKeyTargetsAfterTheirOwningColumns(): void
    {
        $blueprint = new DatabaseSchemaBlueprintOrderingTestBlueprint(
            $this->getConnection('Postgres'),
            'nodes',
            function (DatabaseSchemaBlueprintOrderingTestBlueprint $table) {
                $table->integer('id')->primary();
                // ALTER order is imperative, so this foreign key stays ahead of its later target column and index.
                $table->foreign('parent_id')->references('id')->on('nodes');
                $table->integer('parent_id')->index();
                $table->string('code')->unique('nodes_code_unique');
                $table->text('body')->fullText();
                $table->geometry('location')->spatialIndex();
                $table->vector('embedding', 3)->vectorIndex();
                $table->string('legacy')->unique(false)->change();
            },
        );

        $blueprint->toSql();

        $this->assertSame(
            [
                'add', 'primary', 'foreign', 'add', 'index', 'add', 'unique', 'add', 'add', 'add', 'change',
                'fulltext', 'spatialIndex', 'vectorIndex', 'dropUnique',
                'AutoIncrementStartingValues', 'Comment', 'AutoIncrementStartingValues', 'Comment',
                'AutoIncrementStartingValues', 'Comment', 'AutoIncrementStartingValues', 'Comment',
                'AutoIncrementStartingValues', 'Comment', 'AutoIncrementStartingValues', 'Comment',
                'AutoIncrementStartingValues', 'Comment',
            ],
            array_column($blueprint->getCommands(), 'name'),
        );
        $this->assertSame($blueprint->generatedUniqueCommand, $blueprint->getCommands()[6]);
        $this->assertSame('preserved', $blueprint->getCommands()[6]->testMetadata);
    }

    public function testAlterPreservesExplicitCommandOrder(): void
    {
        $blueprint = $this->getBlueprint('Postgres', 'nodes', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('nodes');
            $table->unique('id');
        });

        $blueprint->toSql();

        $this->assertSame(['foreign', 'unique'], array_column($blueprint->getCommands(), 'name'));
    }

    public function testSqliteAlterKeepsGeneratedIndexAtTheEndOfTheCallbackCommands(): void
    {
        $connection = $this->getConnection('SQLite');
        $connection->getSchemaBuilder()
            ->shouldReceive('parseSchemaAndTable')
            ->with('nodes')
            ->andReturn([null, 'nodes']);

        $blueprint = new Blueprint($connection, 'nodes', function (Blueprint $table) {
            $table->integer('id')->unique();
            $table->integer('value');
        });

        $blueprint->toSql();

        $this->assertSame(['add', 'add', 'unique'], array_column($blueprint->getCommands(), 'name'));
    }

    public function testRenameColumn()
    {
        $getSql = function ($grammar) {
            $connection = $this->getConnection($grammar);
            $connection->shouldReceive('getServerVersion')->andReturn('8.0.4');
            $connection->shouldReceive('isMaria')->andReturn(false);

            return (new Blueprint($connection, 'users', function ($table) {
                $table->renameColumn('foo', 'bar');
            }))->toSql();
        };

        $this->assertEquals(['alter table `users` rename column `foo` to `bar`'], $getSql('MySql'));
        $this->assertEquals(['alter table "users" rename column "foo" to "bar"'], $getSql('Postgres'));
        $this->assertEquals(['alter table "users" rename column "foo" to "bar"'], $getSql('SQLite'));
    }

    public function testNativeRenameColumnOnMysql57()
    {
        $connection = $this->getConnection('MySql');
        $connection->shouldReceive('isMaria')->andReturn(false);
        $connection->shouldReceive('getServerVersion')->andReturn('5.7');
        $connection->getSchemaBuilder()->shouldReceive('getColumns')->andReturn([
            ['name' => 'name', 'type' => 'varchar(255)', 'type_name' => 'varchar', 'nullable' => true, 'collation' => 'utf8mb4_unicode_ci', 'default' => 'foo', 'comment' => null, 'auto_increment' => false, 'generation' => null],
            ['name' => 'id', 'type' => 'bigint unsigned', 'type_name' => 'bigint', 'nullable' => false, 'collation' => null, 'default' => null, 'comment' => 'lorem ipsum', 'auto_increment' => true, 'generation' => null],
            ['name' => 'generated', 'type' => 'int', 'type_name' => 'int', 'nullable' => false, 'collation' => null, 'default' => null, 'comment' => null, 'auto_increment' => false, 'generation' => ['type' => 'stored', 'expression' => 'expression']],
        ]);

        $blueprint = new Blueprint($connection, 'users', function ($table) {
            $table->renameColumn('name', 'title');
            $table->renameColumn('id', 'key');
            $table->renameColumn('generated', 'new_generated');
        });

        $this->assertEquals([
            "alter table `users` change `name` `title` varchar(255) collate 'utf8mb4_unicode_ci' null default 'foo'",
            "alter table `users` change `id` `key` bigint unsigned not null auto_increment comment 'lorem ipsum'",
            'alter table `users` change `generated` `new_generated` int as (expression) stored not null',
        ], $blueprint->toSql());
    }

    public function testNativeRenameColumnOnLegacyMariaDB()
    {
        $connection = $this->getConnection('MariaDb');
        $connection->shouldReceive('isMaria')->andReturn(true);
        $connection->shouldReceive('getServerVersion')->andReturn('10.1.35');
        $connection->getSchemaBuilder()->shouldReceive('getColumns')->andReturn([
            ['name' => 'name', 'type' => 'varchar(255)', 'type_name' => 'varchar', 'nullable' => true, 'collation' => 'utf8mb4_unicode_ci', 'default' => 'foo', 'comment' => null, 'auto_increment' => false, 'generation' => null],
            ['name' => 'id', 'type' => 'bigint unsigned', 'type_name' => 'bigint', 'nullable' => false, 'collation' => null, 'default' => null, 'comment' => 'lorem ipsum', 'auto_increment' => true, 'generation' => null],
            ['name' => 'generated', 'type' => 'int', 'type_name' => 'int', 'nullable' => false, 'collation' => null, 'default' => null, 'comment' => null, 'auto_increment' => false, 'generation' => ['type' => 'stored', 'expression' => 'expression']],
            ['name' => 'foo', 'type' => 'int', 'type_name' => 'int', 'nullable' => true, 'collation' => null, 'default' => 'NULL', 'comment' => null, 'auto_increment' => false, 'generation' => null],
        ]);

        $blueprint = new Blueprint($connection, 'users', function ($table) {
            $table->renameColumn('name', 'title');
            $table->renameColumn('id', 'key');
            $table->renameColumn('generated', 'new_generated');
            $table->renameColumn('foo', 'bar');
        });

        $this->assertEquals([
            "alter table `users` change `name` `title` varchar(255) collate 'utf8mb4_unicode_ci' null default 'foo'",
            "alter table `users` change `id` `key` bigint unsigned not null auto_increment comment 'lorem ipsum'",
            'alter table `users` change `generated` `new_generated` int as (expression) stored not null',
            'alter table `users` change `foo` `bar` int null default NULL',
        ], $blueprint->toSql());
    }

    public function testDropColumn()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'users', function ($table) {
                $table->dropColumn('foo');
            })->toSql();
        };

        $this->assertEquals(['alter table `users` drop `foo`'], $getSql('MySql'));
        $this->assertEquals(['alter table "users" drop column "foo"'], $getSql('Postgres'));
        $this->assertEquals(['alter table "users" drop column "foo"'], $getSql('SQLite'));
    }

    public function testNativeColumnModifyingOnMySql()
    {
        $blueprint = $this->getBlueprint('MySql', 'users', function ($table) {
            $table->double('amount')->nullable()->invisible()->after('name')->change();
            $table->timestamp('added_at', 4)->nullable(false)->useCurrent()->useCurrentOnUpdate()->change();
            $table->enum('difficulty', ['easy', 'hard'])->default('easy')->charset('utf8mb4')->collation('unicode')->change();
            $table->geometry('positions', 'multipolygon', 1234)->storedAs('expression')->change();
            $table->string('old_name', 50)->renameTo('new_name')->change();
            $table->bigIncrements('id')->first()->from(10)->comment('my comment')->change();
        });

        $this->assertEquals([
            'alter table `users` modify `amount` double null invisible after `name`',
            'alter table `users` modify `added_at` timestamp(4) not null default CURRENT_TIMESTAMP(4) on update CURRENT_TIMESTAMP(4)',
            "alter table `users` modify `difficulty` enum('easy', 'hard') character set utf8mb4 collate 'unicode' not null default 'easy'",
            'alter table `users` modify `positions` multipolygon srid 1234 as (expression) stored',
            'alter table `users` change `old_name` `new_name` varchar(50) not null',
            "alter table `users` modify `id` bigint unsigned not null auto_increment comment 'my comment' first",
            'alter table `users` auto_increment = 10',
        ], $blueprint->toSql());
    }

    public function testMacroable()
    {
        Blueprint::macro('foo', function () {
            return $this->addCommand('foo');
        });

        MySqlGrammar::macro('compileFoo', function () {
            return 'bar';
        });

        $blueprint = $this->getBlueprint('MySql', 'users', function ($table) {
            $table->foo();
        });

        $this->assertEquals(['bar'], $blueprint->toSql());
    }

    public function testDefaultUsingIdMorph()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'comments', function ($table) {
                $table->morphs('commentable');
            })->toSql();
        };

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) not null',
            'alter table `comments` add `commentable_id` bigint unsigned not null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $getSql('MySql'));
    }

    public function testDefaultUsingNullableIdMorph()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'comments', function ($table) {
                $table->nullableMorphs('commentable');
            })->toSql();
        };

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) null',
            'alter table `comments` add `commentable_id` bigint unsigned null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $getSql('MySql'));
    }

    public function testDefaultUsingUuidMorph()
    {
        Builder::defaultMorphKeyType('uuid');

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'comments', function ($table) {
                $table->morphs('commentable');
            })->toSql();
        };

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) not null',
            'alter table `comments` add `commentable_id` char(36) not null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $getSql('MySql'));
    }

    public function testDefaultUsingNullableUuidMorph()
    {
        Builder::defaultMorphKeyType('uuid');

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'comments', function ($table) {
                $table->nullableMorphs('commentable');
            })->toSql();
        };

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) null',
            'alter table `comments` add `commentable_id` char(36) null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $getSql('MySql'));
    }

    public function testDefaultUsingUlidMorph()
    {
        Builder::defaultMorphKeyType('ulid');

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'comments', function ($table) {
                $table->morphs('commentable');
            })->toSql();
        };

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) not null',
            'alter table `comments` add `commentable_id` char(26) not null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $getSql('MySql'));
    }

    public function testDefaultUsingNullableUlidMorph()
    {
        Builder::defaultMorphKeyType('ulid');

        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'comments', function ($table) {
                $table->nullableMorphs('commentable');
            })->toSql();
        };

        $this->assertEquals([
            'alter table `comments` add `commentable_type` varchar(255) null',
            'alter table `comments` add `commentable_id` char(26) null',
            'alter table `comments` add index `comments_commentable_type_commentable_id_index`(`commentable_type`, `commentable_id`)',
        ], $getSql('MySql'));
    }

    public function testGenerateRelationshipColumnWithIncrementalModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignIdFor(\Hypervel\Foundation\Auth\User::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `user_id` bigint unsigned not null',
        ], $getSql('MySql'));
    }

    public function testGenerateRelationshipColumnWithNonIncrementalModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignIdFor(Fixtures\Models\EloquentModelUsingNonIncrementedInt::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `model_using_non_incremented_int_id` bigint unsigned not null',
        ], $getSql('MySql'));
    }

    public function testGenerateRelationshipColumnWithUuidModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignIdFor(Fixtures\Models\EloquentModelUsingUuid::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `model_using_uuid_id` char(36) not null',
        ], $getSql('MySql'));
    }

    public function testGenerateUuidRelationshipColumnWithUuidModel(): void
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignUuidFor(Fixtures\Models\EloquentModelUsingUuid::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `model_using_uuid_id` char(36) not null',
        ], $getSql('MySql'));
    }

    public function testGenerateRelationshipColumnWithUlidModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignIdFor(Fixtures\Models\EloquentModelUsingUlid::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table "posts" add column "model_using_ulid_id" char(26) not null',
        ], $getSql('Postgres'));

        $this->assertEquals([
            'alter table `posts` add `model_using_ulid_id` char(26) not null',
        ], $getSql('MySql'));
    }

    public function testGenerateRelationshipConstrainedColumn()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignIdFor(\Hypervel\Foundation\Auth\User::class)->constrained();
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `user_id` bigint unsigned not null',
            'alter table `posts` add constraint `posts_user_id_foreign` foreign key (`user_id`) references `users` (`id`)',
        ], $getSql('MySql'));
    }

    public function testGenerateUuidRelationshipConstrainedColumn(): void
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignUuidFor(Fixtures\Models\EloquentModelUsingUuid::class)->constrained();
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `model_using_uuid_id` char(36) not null',
            'alter table `posts` add constraint `posts_model_using_uuid_id_foreign` foreign key (`model_using_uuid_id`) references `model` (`id`)',
        ], $getSql('MySql'));
    }

    public function testGenerateRelationshipForModelWithNonStandardPrimaryKeyName()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->foreignIdFor(User::class)->constrained();
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `user_internal_id` bigint unsigned not null',
            'alter table `posts` add constraint `posts_user_internal_id_foreign` foreign key (`user_internal_id`) references `users` (`internal_id`)',
        ], $getSql('MySql'));
    }

    public function testDropRelationshipColumnWithIncrementalModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->dropForeignIdFor(\Hypervel\Foundation\Auth\User::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` drop `user_id`',
        ], $getSql('MySql'));
    }

    public function testDropRelationshipColumnWithUuidModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->dropForeignIdFor(Fixtures\Models\EloquentModelUsingUuid::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` drop `model_using_uuid_id`',
        ], $getSql('MySql'));
    }

    public function testDropConstrainedRelationshipColumnWithIncrementalModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->dropConstrainedForeignIdFor(\Hypervel\Foundation\Auth\User::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` drop foreign key `posts_user_id_foreign`',
            'alter table `posts` drop `user_id`',
        ], $getSql('MySql'));
    }

    public function testDropConstrainedRelationshipColumnWithUuidModel()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->dropConstrainedForeignIdFor(Fixtures\Models\EloquentModelUsingUuid::class);
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` drop foreign key `posts_model_using_uuid_id_foreign`',
            'alter table `posts` drop `model_using_uuid_id`',
        ], $getSql('MySql'));
    }

    public function testTinyTextColumn()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->tinyText('note');
            })->toSql();
        };

        $this->assertEquals(['alter table `posts` add `note` tinytext not null'], $getSql('MySql'));
        $this->assertEquals(['alter table "posts" add column "note" text not null'], $getSql('SQLite'));
        $this->assertEquals(['alter table "posts" add column "note" varchar(255) not null'], $getSql('Postgres'));
    }

    public function testTinyTextNullableColumn()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->tinyText('note')->nullable();
            })->toSql();
        };

        $this->assertEquals(['alter table `posts` add `note` tinytext null'], $getSql('MySql'));
        $this->assertEquals(['alter table "posts" add column "note" text'], $getSql('SQLite'));
        $this->assertEquals(['alter table "posts" add column "note" varchar(255) null'], $getSql('Postgres'));
    }

    public function testRawColumn()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->rawColumn('legacy_boolean', 'INT(1)')->nullable();
            })->toSql();
        };

        $this->assertEquals([
            'alter table `posts` add `legacy_boolean` INT(1) null',
        ], $getSql('MySql'));

        $this->assertEquals([
            'alter table "posts" add column "legacy_boolean" INT(1)',
        ], $getSql('SQLite'));

        $this->assertEquals([
            'alter table "posts" add column "legacy_boolean" INT(1) null',
        ], $getSql('Postgres'));
    }

    public function testTableComment()
    {
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->comment('Look at my comment, it is amazing');
            })->toSql();
        };

        $this->assertEquals(['alter table `posts` comment = \'Look at my comment, it is amazing\''], $getSql('MySql'));
        $this->assertEquals(['comment on table "posts" is \'Look at my comment, it is amazing\''], $getSql('Postgres'));
    }

    public function testColumnDefault()
    {
        // Test a normal string literal column default.
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->tinyText('note')->default('this will work');
            })->toSql();
        };

        $this->assertEquals(['alter table `posts` add `note` tinytext not null default \'this will work\''], $getSql('MySql'));

        // Test a string literal column default containing an apostrophe (#56124)
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $table->tinyText('note')->default('this\'ll work too');
            })->toSql();
        };

        $this->assertEquals(['alter table `posts` add `note` tinytext not null default \'this\'\'ll work too\''], $getSql('MySql'));

        // Test a backed enumeration column default
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $enum = ApostropheBackedEnum::ValueWithoutApostrophe;
                $table->tinyText('note')->default($enum);
            })->toSql();
        };
        $this->assertEquals(['alter table `posts` add `note` tinytext not null default \'this will work\''], $getSql('MySql'));

        // Test a backed enumeration column default containing an apostrophe (#56124)
        $getSql = function ($grammar) {
            return $this->getBlueprint($grammar, 'posts', function ($table) {
                $enum = ApostropheBackedEnum::ValueWithApostrophe;
                $table->tinyText('note')->default($enum);
            })->toSql();
        };
        $this->assertEquals(['alter table `posts` add `note` tinytext not null default \'this\'\'ll work too\''], $getSql('MySql'));
    }

    protected function getConnection(?string $grammar = null, string $prefix = '')
    {
        $connection = m::mock(Connection::class)
            ->shouldReceive('getTablePrefix')->andReturn($prefix)
            ->shouldReceive('getConfig')->with('prefix_indexes')->andReturn(true)
            ->getMock();

        $grammar ??= 'MySql';
        $grammarClass = 'Hypervel\Database\Schema\Grammars\\' . $grammar . 'Grammar';
        $builderClass = 'Hypervel\Database\Schema\\' . $grammar . 'Builder';

        $connection->shouldReceive('getSchemaGrammar')->andReturn(new $grammarClass($connection));
        $connection->shouldReceive('getSchemaBuilder')->andReturn(m::mock($builderClass));

        if ($grammar === 'SQLite') {
            $connection->shouldReceive('getServerVersion')->andReturn('3.35');
        }

        if ($grammar === 'MySql') {
            $connection->shouldReceive('isMaria')->andReturn(false);
        }

        return $connection;
    }

    protected function getBlueprint(
        ?string $grammar = null,
        string $table = '',
        ?Closure $callback = null,
        string $prefix = ''
    ): Blueprint {
        $connection = $this->getConnection($grammar, $prefix);

        return new Blueprint($connection, $table, $callback);
    }
}

enum ApostropheBackedEnum: string
{
    case ValueWithoutApostrophe = 'this will work';
    case ValueWithApostrophe = 'this\'ll work too';
}

class DatabaseSchemaBlueprintOrderingTestBlueprint extends Blueprint
{
    public ?IndexDefinition $generatedUniqueCommand = null;

    /**
     * Add a custom schema command.
     */
    public function customCommand(): Fluent
    {
        return $this->addCommand('custom');
    }

    /**
     * Specify a unique index for the table.
     */
    public function unique(array|string $columns, ?string $name = null, ?string $algorithm = null): IndexDefinition
    {
        $command = parent::unique($columns, $name, $algorithm);
        $command->testMetadata = 'preserved';
        $this->generatedUniqueCommand = $command;

        return $command;
    }
}
