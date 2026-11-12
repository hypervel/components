<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Database\Eloquent\Concerns\HasUniqueStringIds;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\SchemaAggregator;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\PHPStan\Properties\SchemaTable;
use Hypervel\Tests\PHPStan\Fixtures\OverridesConfig;
use Hypervel\Tests\PHPStan\Fixtures\ReadsSchemaFacts;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\Author;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\ConnectionPropertyMigration;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\Constants;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\InheritedConstants;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\KeyedByUuid;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\Person;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\TypedConstants;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

use function array_keys;
use function array_map;

class SchemaAggregatorTest extends PHPStanTestCase
{
    use OverridesConfig;
    use ReadsSchemaFacts;

    #[Test]
    #[DataProvider('tableConstants')]
    public function itResolvesTableConstantInitializers(string $constant): void
    {
        $aggregator = $this->aggregate(<<<PHP
            Schema::create({$constant}, function (Blueprint \$table) {
                \$table->id();
            });

            Schema::table({$constant}, function (Blueprint \$table) {
                \$table->string('email')->nullable();
            });
        PHP);

        $this->assertSame([
            'id' => ['int<0, max>|numeric-string', false],
            'email' => ['string', true],
        ], $this->columns($aggregator, 'users'));
    }

    /** @return iterable<string, array{string}> */
    public static function tableConstants(): iterable
    {
        yield 'untyped' => ['Constants::USERS'];
        yield 'PHPDoc type' => ['Constants::DOCUMENTED_USERS'];
        yield 'inherited initializer' => ['InheritedConstants::DOCUMENTED_USERS'];
        yield 'native type' => ['TypedConstants::USERS'];
        yield 'typed expression' => ['TypedConstants::CONCATENATED_USERS'];
    }

    #[Test]
    public function itAppliesCreateTableSqlFromStatementCalls(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            DB::statement('CREATE TABLE `users` (
                `id` int unsigned NOT NULL,
                `email` varchar(255) DEFAULT NULL
            )');

            Schema::table('users', function (Blueprint $table) {
                $table->string('name');
            });

            DB::connection('mysql')->statement('CREATE TABLE `reports` (`id` int unsigned NOT NULL)');

            \Hypervel\Support\Facades\DB::unprepared(<<<'SQL'
                CREATE TABLE `posts` (`id` int NOT NULL);
                CREATE TABLE `comments` (`id` int NOT NULL, `body` varchar(255) NULL);
            SQL);
        PHP);

        $this->assertSame([
            'id' => ['int<0, max>', false],
            'email' => ['string', true],
            'name' => ['string', false],
        ], $this->columns($aggregator, 'users'));
        $this->assertSame(['id' => ['int<0, max>', false]], $this->columns($aggregator, 'reports'));
        $this->assertSame(['id' => ['int', false]], $this->columns($aggregator, 'posts'));
        $this->assertSame(['id' => ['int', false], 'body' => ['string', true]], $this->columns($aggregator, 'comments'));
        $this->assertTrue($this->table($aggregator, 'comments')->complete);
    }

    /**
     * Unlike upstream, which kept the Blueprint columns, statements that change a known relation make it unknown.
     */
    #[Test]
    public function itForgetsRelationsRawSqlChanges(): void
    {
        foreach ([
            'DB facade' => ['', 'DB::statement'],
            'Schema facade connection' => ['', 'Schema::getConnection()->statement'],
            'schema builder connection' => ["\$schema = Schema::connection('mysql');", '$schema->getConnection()->statement'],
        ] as $case => [$setup, $statement]) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::create('users', function (Blueprint \$table) {
                    \$table->string('name');
                });

                Schema::create('posts', function (Blueprint \$table) {
                    \$table->string('title');
                });

                {$setup}
                {$statement}('ALTER TABLE users RENAME COLUMN name TO full_name');
                {$statement}('INSERT INTO posts (title) VALUES (1)');
            PHP);

            $this->assertSame([], $this->columns($aggregator, 'users'), $case);
            $this->assertFalse($this->table($aggregator, 'users')->complete, $case);
            $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts'), $case);
            $this->assertTrue($this->table($aggregator, 'posts')->complete, $case);
            $this->assertTrue($this->schema($aggregator)->complete, $case);
        }
    }

    #[Test]
    #[DataProvider('unreadableSql')]
    public function itForgetsTheConnectionUnreadableSqlRunsOn(string $statement): void
    {
        $aggregator = $this->aggregate(<<<PHP
            Schema::create('users', function (Blueprint \$table) {
                \$table->string('name');
            });

            {$statement}
        PHP);

        $this->assertNull($this->columns($aggregator, 'users'));
        $this->assertFalse($this->schema($aggregator)->complete);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableSql(): iterable
    {
        yield 'rejected by the parser' => ["DB::statement('CREATE TABLE `broken (id INT)');"];
        yield 'not a literal' => ["\$sql = 'CREATE TABLE `skipped` (`id` int NOT NULL)';\nDB::statement(\$sql);"];
    }

    #[Test]
    #[DataProvider('indexMethodCalls')]
    public function itKeepsColumnsUnchangedByIndexMethods(string $indexMethodCall): void
    {
        $aggregator = $this->aggregate(<<<PHP
            Schema::create('posts', function (Blueprint \$table) {
                \$table->id();
                \$table->string('body')->nullable();
                \$table->geometry('location', 'point')->nullable();
                \$table->vector('embedding', 3)->nullable();
            });

            Schema::table('posts', function (Blueprint \$table) {
                {$indexMethodCall}
            });
        PHP);

        $this->assertSame([
            'id' => ['int<0, max>|numeric-string', false],
            'body' => ['string', true],
            'location' => [null, true],
            'embedding' => [null, true],
        ], $this->columns($aggregator, 'posts'));
        $this->assertTrue($this->table($aggregator, 'posts')->complete);
    }

    /** @return iterable<string, array{string}> */
    public static function indexMethodCalls(): iterable
    {
        yield 'fullText' => ["\$table->fullText('body');"];
        yield 'dropFullText' => ["\$table->dropFullText('posts_body_fulltext');"];
        yield 'spatialIndex' => ["\$table->spatialIndex('location');"];
        yield 'dropSpatialIndex' => ["\$table->dropSpatialIndex('posts_location_spatialindex');"];
        yield 'vectorIndex' => ["\$table->vectorIndex('embedding');"];
        yield 'rawIndex' => ["\$table->rawIndex('lower(body)', 'posts_body_lower_index');"];
    }

    /**
     * The engine, charset and collation properties choose table options, so setting them changes no column.
     */
    #[Test]
    public function itKeepsColumnsUnchangedByTableOptionProperties(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
                $table->string('name');
            });
        PHP);

        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
    }

    #[Test]
    public function itResolvesColumnNameConstants(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('compensations', function (Blueprint $table) {
                $table->id();
                $table->decimal(Constants::AMOUNT, 10, 2)->nullable();
                $table->string(Constants::MISSING);
            });
        PHP);

        // An added column with an unknown name keeps the known columns.
        $this->assertSame([
            'id' => ['int<0, max>|numeric-string', false],
            'amount' => ['numeric-string', true],
        ], $this->columns($aggregator, 'compensations'));
        $this->assertFalse($this->table($aggregator, 'compensations')->complete);

        $aggregator = $this->aggregate(<<<'PHP'
            Schema::table('compensations', function (Blueprint $table) {
                $table->string(Constants::MISSING)->change();
            });
        PHP, aggregator: $aggregator);

        // change() may modify any known column.
        $this->assertSame([], $this->columns($aggregator, 'compensations'));
    }

    /**
     * The files a table's columns are read from are its sources, while the files that can change which tables exist
     * or which connection a migration runs on are sources of every fact.
     */
    #[Test]
    public function itRecordsTheFilesFactsAreReadFrom(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create(InheritedConstants::DOCUMENTED_USERS, function (Blueprint $table) {
                $table->decimal(Constants::AMOUNT, 10, 2);
                $table->foreignIdFor(Author::class);
            });
        PHP);

        // The nested trait makes the key a UUID.
        $this->assertSame([
            'amount' => ['numeric-string', false],
            'author_id' => ['string', false],
        ], $this->columns($aggregator, 'users'));
        // The model's sources include the constant its table property reads.
        $this->assertEqualsCanonicalizing(
            $this->fileNames(Constants::class, Author::class, Person::class, KeyedByUuid::class, HasUuids::class, HasUniqueStringIds::class, TypedConstants::class),
            array_keys($this->table($aggregator, 'users')->sources),
        );
        // A constant read through a subclass changes when the subclass overrides it.
        $this->assertEqualsCanonicalizing($this->fileNames(InheritedConstants::class, Constants::class), array_keys($aggregator->sources));

        $aggregator = $this->apply(<<<'PHP'
            return new class extends ConnectionPropertyMigration
            {
                public function up(): void
                {
                }
            };
        PHP);

        $this->assertSame($this->fileNames(ConnectionPropertyMigration::class), array_keys($aggregator->sources));
    }

    #[Test]
    public function itReadsColumnsFromMorphMethods(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('attachments', function (Blueprint $table) {
                $table->numericMorphs('numeric');
                $table->nullableNumericMorphs('nullable_numeric');
                $table->uuidMorphs('uuid');
                $table->nullableUuidMorphs('nullable_uuid');
                $table->ulidMorphs('ulid');
                $table->nullableUlidMorphs('nullable_ulid');
            });
        PHP);

        $this->assertSame([
            'numeric_type' => ['string', false],
            'numeric_id' => ['int<0, max>|numeric-string', false],
            'nullable_numeric_type' => ['string', true],
            'nullable_numeric_id' => ['int<0, max>|numeric-string', true],
            'uuid_type' => ['string', false],
            'uuid_id' => ['string', false],
            'nullable_uuid_type' => ['string', true],
            'nullable_uuid_id' => ['string', true],
            'ulid_type' => ['string', false],
            'ulid_id' => ['string', false],
            'nullable_ulid_type' => ['string', true],
            'nullable_ulid_id' => ['string', true],
        ], $this->columns($aggregator, 'attachments'));
    }

    /**
     * Unlike upstream, which skipped the calls, names that are not strings make what they change unknown.
     */
    #[Test]
    #[DataProvider('enumCases')]
    public function itForgetsWhatEnumCaseNamesChange(string $case): void
    {
        $aggregator = $this->aggregate(<<<PHP
            Schema::create('statuses', function (Blueprint \$table) {
                \$table->id();
                \$table->string({$case});
            });
        PHP);

        $this->assertSame(['id' => ['int<0, max>|numeric-string', false]], $this->columns($aggregator, 'statuses'));
        $this->assertFalse($this->table($aggregator, 'statuses')->complete);

        $aggregator = $this->aggregate(<<<PHP
            Schema::create({$case}, function (Blueprint \$table) {
                \$table->id();
            });
        PHP, aggregator: $aggregator);

        $this->assertFalse($this->schema($aggregator)->complete);
    }

    /** @return iterable<string, array{string}> */
    public static function enumCases(): iterable
    {
        yield 'pure' => ['PureStatus::Draft'];
        yield 'backed' => ['BackedStatus::Draft'];
    }

    #[Test]
    public function itReadsArrowFunctionMigrationCallbacks(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->id());

            Schema::table('users', fn (Blueprint $table) => $table->string('kyc_status')->nullable());

            Schema::table('users', function (Blueprint $table) {
                $table->after('id', fn (Blueprint $table) => $table->integer('age'));
            });
        PHP);

        $this->assertSame([
            'id' => ['int<0, max>|numeric-string', false],
            'kyc_status' => ['string', true],
            'age' => ['int', false],
        ], $this->columns($aggregator, 'users'));
    }

    /**
     * Unlike upstream, which skipped them, callbacks the aggregator cannot follow make their table unknown.
     */
    #[Test]
    public function itForgetsTablesUnfollowableCallbacksChange(): void
    {
        foreach ([
            "Schema::table('users', fn (NotABlueprint \$table) => \$table->string('name'));",
            "Schema::table('users', fn () => null);",
            "Schema::table('users', function (Blueprint \$table) {\n    \$table->after('id', fn () => null);\n});",
            "Schema::table('users', \$callback);",
        ] as $statement) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::create('users', fn (Blueprint \$table) => \$table->id());

                Schema::create('posts', fn (Blueprint \$table) => \$table->id());

                {$statement}
            PHP);

            $this->assertSame([], $this->columns($aggregator, 'users'), $statement);
            $this->assertFalse($this->table($aggregator, 'users')->complete, $statement);
            $this->assertSame(['id' => ['int<0, max>|numeric-string', false]], $this->columns($aggregator, 'posts'), $statement);
        }
    }

    #[Test]
    public function itLeavesNoMissingColumnClaimAfterRawDropsAndRenames(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));
            Schema::create('posts', fn (Blueprint $table) => $table->string('title'));
            Schema::create('tags', fn (Blueprint $table) => $table->string('label'));

            DB::statement('DROP TABLE users');
            DB::statement('ALTER TABLE posts RENAME TO articles');
            DB::statement('CREATE INDEX tags_label_index ON tags (label)');
        PHP);

        foreach (['users', 'posts', 'articles'] as $table) {
            $this->assertSame([], $this->columns($aggregator, $table), $table);
            $this->assertFalse($this->table($aggregator, $table)->complete, $table);
        }

        // Index statements keep the facts.
        $this->assertSame(['label' => ['string', false]], $this->columns($aggregator, 'tags'));
        $this->assertTrue($this->table($aggregator, 'tags')->complete);

        // A table the same input creates and alters is unknown, so an alteration the parser could not apply leaves no column behind.
        $aggregator = $this->aggregate("DB::statement('CREATE TABLE users (id INTEGER, name TEXT); ALTER TABLE users DROP COLUMN name;');", 'sqlite');

        $this->assertSame([], $this->columns($aggregator, 'users', 'sqlite'));
        $this->assertFalse($this->table($aggregator, 'users', 'sqlite')->complete);
    }

    /**
     * The snapshot does not distinguish IF NOT EXISTS, so a definition of a relation that may exist leaves it unknown.
     */
    #[Test]
    #[DataProvider('redefinitions')]
    public function itForgetsARelationRawSqlMayRedefine(string $statement): void
    {
        $aggregator = $this->aggregate(<<<PHP
            Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));

            {$statement}
        PHP);

        $this->assertSame([], $this->columns($aggregator, 'users'));
        $this->assertFalse($this->table($aggregator, 'users')->complete);
    }

    /** @return iterable<string, array{string}> */
    public static function redefinitions(): iterable
    {
        yield 'if not exists' => ["DB::statement('CREATE TABLE IF NOT EXISTS users (id int)');"];
        yield 'drop and recreate' => ["DB::unprepared('DROP TABLE users; CREATE TABLE users (id int)');"];
        yield 'case variant' => ["DB::statement('CREATE TABLE Users (id int)');"];
    }

    /**
     * MySQL compares table names without case on some servers, so changing one spelling forgets the others.
     */
    #[Test]
    public function itForgetsMySqlCaseVariantsAChangeMayReach(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));
            Schema::create('posts', fn (Blueprint $table) => $table->string('title'));

            DB::statement('ALTER TABLE Users ADD COLUMN age int');

            Schema::table('POSTS', fn (Blueprint $table) => $table->string('body'));
        PHP);

        $this->assertSame([], $this->columns($aggregator, 'users'));
        $this->assertSame([], $this->columns($aggregator, 'posts'));
        $this->assertSame(['body' => ['string', false]], $this->columns($aggregator, 'POSTS'));
    }

    /**
     * SQLite compares names without case, so its identities do too.
     */
    #[Test]
    public function itMatchesSqliteNamesWithoutCase(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('Users', fn (Blueprint $table) => $table->string('name'));
            Schema::table('users', fn (Blueprint $table) => $table->string('email'));
        PHP, 'sqlite');

        $this->assertSame(['name' => ['string', false], 'email' => ['string', false]], $this->columns($aggregator, 'USERS', 'sqlite'));
    }

    /**
     * MySQL and SQLite match the column a rename, drop or change() names in any letter case.
     */
    #[Test]
    public function itMatchesChangedColumnsWithoutCase(): void
    {
        foreach (['mysql', 'sqlite'] as $connection) {
            $aggregator = $this->aggregate(<<<'PHP'
                Schema::create('users', function (Blueprint $table) {
                    $table->string('Name');
                    $table->string('Email');
                    $table->string('Title');
                });

                Schema::table('users', function (Blueprint $table) {
                    $table->renameColumn('name', 'full_name');
                    $table->dropColumn('email');
                });
            PHP, $connection);

            $this->assertSame(['Title' => ['string', false], 'full_name' => ['string', false]], $this->columns($aggregator, 'users', $connection), $connection);

            // The spelling a column keeps after change() depends on the database.
            $aggregator = $this->aggregate("Schema::table('users', fn (Blueprint \$table) => \$table->text('title')->change());", $connection, $aggregator);

            $this->assertSame(['full_name' => ['string', false]], $this->columns($aggregator, 'users', $connection), $connection);
            $this->assertFalse($this->table($aggregator, 'users', $connection)->complete);
        }
    }

    /**
     * addColumn() merges literal parameters over its type and name, and the chain's modifiers override them.
     */
    #[Test]
    public function itAppliesAddColumnParameters(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', function (Blueprint $table) {
                $table->addColumn('string', 'name', ['nullable' => true]);
                $table->addColumn('string', 'email', ['nullable' => true])->nullable(false);
                $table->addColumn('string', 'replaced', ['type' => 'integer', 'name' => 'votes', 'unsigned' => true]);
            });

            Schema::create('posts', function (Blueprint $table) {
                $table->string('title');
                $table->addColumn('string', 'body', $this->parameters);
            });
        PHP, members: 'public array $parameters = [];');

        $this->assertSame(
            ['name' => ['string', true], 'email' => ['string', false], 'votes' => ['int<0, max>', false]],
            $this->columns($aggregator, 'users'),
        );
        // Unknown parameters can set any attribute, so the table is unknown.
        $this->assertFalse($this->table($aggregator, 'posts')->complete);
        $this->assertSame([], $this->columns($aggregator, 'posts'));
    }

    /**
     * A column's Fluent mutators can set any attribute, so the table is unknown, while a foreign key's keep the column.
     */
    #[Test]
    public function itForgetsATableWhoseColumnAFluentMutatorChanges(): void
    {
        foreach ([
            'fill' => "\$table->string('name')->fill(['nullable' => true]);",
            'set' => "\$table->string('name')->set('type', 'integer');",
            'offsetSet' => "\$table->string('name')->offsetSet('nullable', true);",
            'offsetUnset' => "\$table->string('name')->nullable()->offsetUnset('nullable');",
            'foreign key' => "\$table->foreignId('user_id')->constrained()->fill(['onDelete' => 'cascade']);",
        ] as $case => $statement) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::create('users', function (Blueprint \$table) {
                    {$statement}
                });
            PHP);

            $this->assertSame($case === 'foreign key', $this->table($aggregator, 'users')->complete, $case);
            $this->assertSame($case === 'foreign key' ? ['user_id' => ['int<0, max>|numeric-string', false]] : [], $this->columns($aggregator, 'users'), $case);
        }
    }

    /**
     * A type() modifier replaces the column's type; a type that is not constant leaves it unknown.
     */
    #[Test]
    public function itAppliesTheTypeModifier(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', function (Blueprint $table) {
                $table->string('votes')->type('integer');
                $table->foreignIdFor(Author::class)->type('integer');
                $table->string('dynamic')->type($this->type);
            });
        PHP, members: 'public string $type = "integer";');

        $this->assertSame(
            ['votes' => ['int', false], 'author_id' => ['int', false], 'dynamic' => [null, false]],
            $this->columns($aggregator, 'users'),
        );
    }

    /**
     * A foreign ID column's constrained() and references() return its foreign key, so the calls after them leave the
     * column alone. On another column they are attributes that return the column.
     */
    #[Test]
    public function itAppliesOnlyTheColumnModifiersBeforeAForeignKey(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('posts', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->nullable();
                $table->foreignId('editor_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignUuid('owner_id')->references('id')->on('owners')->nullable();
                $table->unsignedBigInteger('team_id')->references('id')->nullable();
            });
        PHP);

        $this->assertSame(
            [
                'user_id' => ['int<0, max>|numeric-string', false],
                'editor_id' => ['int<0, max>|numeric-string', true],
                'owner_id' => ['string', false],
                'team_id' => ['int<0, max>|numeric-string', true],
            ],
            $this->columns($aggregator, 'posts'),
        );
    }

    /**
     * Unlike upstream's drop, removeColumn() only cancels the definitions the Blueprint queued.
     */
    #[Test]
    public function itKeepsAColumnRemoveColumnCancels(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::table('users', function (Blueprint $table) {
                $table->integer('name')->change();
                $table->removeColumn('name');
            });
        PHP);

        $this->assertSame(['name' => [null, true]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
    }

    /**
     * removeColumn() returns the Blueprint, so a call chained after it changes the table.
     */
    #[Test]
    public function itForgetsATableChangedAfterRemoveColumn(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::table('users', function (Blueprint $table) {
                $table->removeColumn('nickname')->string('email');
            });
        PHP);

        $this->assertSame([], $this->columns($aggregator, 'users'));
        $this->assertFalse($this->table($aggregator, 'users')->complete);
    }

    #[Test]
    public function itDecidesColumnGuardsAndFollowsBothOutcomesOtherwise(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', function (Blueprint $table) {
                $table->string('name');
                $table->string('nickname');
            });

            Schema::table('users', fn (Blueprint $table) => $table->removeColumn('nickname'));

            Schema::whenTableHasColumn('users', 'NAME', fn (Blueprint $table) => $table->string('present'));
            Schema::whenTableDoesntHaveColumn('users', 'missing', fn (Blueprint $table) => $table->string('absent'));
            Schema::whenTableHasColumn('users', 'missing', fn (Blueprint $table) => $table->string('skipped'));
            Schema::whenTableHasColumn('users', 'nickname', fn (Blueprint $table) => $table->string('undecided'));
            Schema::whenTableHasIndex('users', 'users_name_index', fn (Blueprint $table) => $table->string('indexed'));
        PHP);

        $this->assertSame([
            'name' => ['string', false],
            'nickname' => [null, true],
            'present' => ['string', false],
            'absent' => ['string', false],
            'undecided' => [null, true],
            'indexed' => [null, true],
        ], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
    }

    #[Test]
    public function itJoinsBranchesAndEarlyReturns(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            if ($this->flag) {
                Schema::table('users', fn (Blueprint $table) => $table->string('both'));
            } else {
                Schema::table('users', function (Blueprint $table) {
                    $table->string('both');
                    $table->string('one');
                });
            }

            if ($this->flag) {
                return;
            }

            Schema::create('posts', fn (Blueprint $table) => $table->string('title'));
        PHP, members: 'public bool $flag = true;');

        $this->assertSame([
            'name' => ['string', false],
            'both' => ['string', false],
            'one' => [null, true],
        ], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);

        // A table one outcome created may not exist.
        $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts'));
        $this->assertFalse($this->table($aggregator, 'posts')->complete);
    }

    /**
     * Elseif and case conditions run on the way to later outcomes, but not after a condition that is certainly true.
     */
    #[Test]
    public function itAppliesTheConditionsEachOutcomeRuns(): void
    {
        $create = "Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));";

        // The first outcome does not run the elseif condition; the case condition runs before any outcome.
        foreach ([
            'elseif' => ["if (\$this->flag) {\n} elseif (Schema::dropIfExists('users') === null) {\n}", ['name' => [null, true]]],
            'switch case' => ["switch (\$this->flag) {\n    case Schema::dropIfExists('users') === null:\n        break;\n}", []],
        ] as $case => [$statements, $columns]) {
            $aggregator = $this->aggregate("{$create}\n{$statements}", members: 'public bool $flag = true;');

            $this->assertSame($columns, $this->columns($aggregator, 'users'), $case);
            $this->assertFalse($this->table($aggregator, 'users')->complete, $case);
        }

        $aggregator = $this->aggregate(<<<PHP
            {$create}

            if (Schema::hasTable('users')) {
                Schema::table('users', fn (Blueprint \$table) => \$table->string('email'));
            } elseif (Schema::dropIfExists('users') === null) {
            }
        PHP);

        $this->assertSame(['name' => ['string', false], 'email' => ['string', false]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
    }

    /**
     * Blueprint commands run once the callback returns, so a guard on the callback's own table reads it as it was before them.
     */
    #[Test]
    public function itDecidesGuardsInABlueprintCallbackFromTheTableBeforeIt(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', function (Blueprint $table) {
                $table->string('name');
                $table->string('status');
            });

            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'status')) {
                    $table->dropColumn('status');
                }

                if (Schema::hasColumn('users', 'status')) {
                    $table->string('state');
                }
            });

            Schema::create('posts', function (Blueprint $table) {
                $table->string('title');

                if (! Schema::hasTable('posts')) {
                    $table->string('body');
                }
            });
        PHP);

        $this->assertSame(['name' => ['string', false], 'state' => ['string', false]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
        $this->assertSame(['title' => ['string', false], 'body' => ['string', false]], $this->columns($aggregator, 'posts'));
        $this->assertTrue($this->table($aggregator, 'posts')->complete);

        // Another Schema::table() call runs at once, so the table is not known before the guard.
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::table('users', function (Blueprint $table) {
                Schema::table('users', fn (Blueprint $inner) => $inner->string('nickname'));

                if (Schema::hasColumn('users', 'nickname')) {
                    $table->string('alias');
                }
            });
        PHP);

        $this->assertSame(['alias' => [null, true]], $this->columns($aggregator, 'users'));
        $this->assertFalse($this->table($aggregator, 'users')->complete);

        // Connections whose migrations run on one connection can resolve the same name to different tables.
        $mysql = config('database.connections.mysql');

        $aggregator = $this->withConfig(
            ['database.connections.mysql_other' => [...$mysql, 'database' => 'other', 'migrations_connection' => 'mysql']],
            fn (): SchemaAggregator => $this->aggregate(<<<'PHP'
                Schema::create('users', fn (Blueprint $table) => $table->string('name'));
                Schema::connection('mysql_other')->create('users', fn (Blueprint $table) => $table->string('email'));

                Schema::table('users', function (Blueprint $table) {
                    if (Schema::connection('mysql_other')->hasColumn('users', 'email')) {
                        $table->string('alias');
                    }
                });
            PHP),
        );

        $this->assertSame(['name' => ['string', false], 'alias' => ['string', false]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);

        $aggregator = $this->withConfig(
            [
                'database.connections.pgsql_a' => ['driver' => 'pgsql', 'search_path' => 'a'],
                'database.connections.pgsql_b' => ['driver' => 'pgsql', 'search_path' => 'b', 'migrations_connection' => 'pgsql_a'],
            ],
            fn (): SchemaAggregator => $this->aggregate(<<<'PHP'
                Schema::create('a.users', fn (Blueprint $table) => $table->string('name'));
                Schema::create('b.users', fn (Blueprint $table) => $table->string('email'));

                Schema::table('users', function (Blueprint $table) {
                    if (Schema::connection('pgsql_b')->hasColumn('users', 'email')) {
                        $table->string('alias');
                    }
                });
            PHP, 'pgsql_a'),
        );

        $this->assertSame(['name' => ['string', false], 'alias' => ['string', false]], $this->columns($aggregator, 'a.users', 'pgsql_a'));
        $this->assertTrue($this->table($aggregator, 'a.users', 'pgsql_a')->complete);

        // A queued drop or rename changes which tables exist only once the callback returns, so the
        // facts cannot decide guards on any name or connection the change reaches until then.
        $aggregator = $this->withConfig(
            [
                'database.connections.pgsql_ab' => ['driver' => 'pgsql', 'search_path' => 'a,b'],
                'database.connections.pgsql_ac' => ['driver' => 'pgsql', 'search_path' => 'a,c', 'migrations_connection' => 'pgsql_ab'],
            ],
            fn (): SchemaAggregator => $this->aggregate(<<<'PHP'
                Schema::create('a.users', fn (Blueprint $table) => $table->string('name'));
                Schema::create('a.posts', fn (Blueprint $table) => $table->string('legacy'));

                Schema::table('users', function (Blueprint $table) {
                    $table->drop();

                    if (Schema::connection('pgsql_ac')->hasTable('users')) {
                        Schema::table('a.posts', fn (Blueprint $posts) => $posts->dropColumn('legacy'));
                    }
                });
            PHP, 'pgsql_ab'),
        );

        $this->assertSame(['legacy' => [null, true]], $this->columns($aggregator, 'a.posts', 'pgsql_ab'));
        $this->assertFalse($this->table($aggregator, 'a.posts', 'pgsql_ab')->complete);

        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::create('posts', function (Blueprint $table) {
                $table->string('title');
                $table->string('legacy');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->rename('people');

                if (! Schema::hasTable('people')) {
                    Schema::table('posts', fn (Blueprint $posts) => $posts->dropColumn('legacy'));
                }
            });
        PHP);

        $this->assertSame(['title' => [null, true], 'legacy' => [null, true]], $this->columns($aggregator, 'posts'));
        $this->assertFalse($this->table($aggregator, 'posts')->complete);
        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'people'));
        $this->assertTrue($this->table($aggregator, 'people')->complete);
    }

    #[Test]
    public function itForgetsWhatASwitchLeftPartwayChanges(): void
    {
        $members = 'public bool $flag = true; public array $items = [];';

        foreach ([
            'if' => "if (\$this->flag) {\n    break;\n}",
            'nested loop' => "foreach (\$this->items as \$item) {\n    break 2;\n}",
        ] as $case => $exit) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));

                switch (\$this->flag) {
                    default:
                        {$exit}

                        Schema::table('users', fn (Blueprint \$table) => \$table->string('email'));
                }
            PHP, members: $members);

            $this->assertSame([], $this->columns($aggregator, 'users'), $case);
            $this->assertFalse($this->table($aggregator, 'users')->complete, $case);
        }

        // A break that leaves only a nested loop or switch keeps the case going.
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            switch ($this->flag) {
                default:
                    foreach ($this->items as $item) {
                        break;
                    }

                    switch ($this->flag) {
                        case true:
                            if ($this->flag) {
                                break;
                            }
                    }

                    Schema::table('users', fn (Blueprint $table) => $table->string('email'));
            }
        PHP, members: $members);

        $this->assertSame(['name' => ['string', false], 'email' => ['string', false]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
    }

    #[Test]
    public function itAppliesHelperMethodsAndForeignKeyFreeCallbacks(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            $this->createUsers();
            $this::addNickname();

            Schema::withoutForeignKeyConstraints(function () {
                Schema::table('users', fn (Blueprint $table) => $table->string('email'));
            });
        PHP, members: <<<'PHP'
            private function createUsers(): void
            {
                Schema::create('users', fn (Blueprint $table) => $table->string('name'));

                $this->createUsers();
            }

            private function addNickname(): void
            {
                Schema::table('users', fn (Blueprint $table) => $table->string('nickname'));
            }
        PHP);

        $this->assertSame(['name' => ['string', false], 'nickname' => ['string', false], 'email' => ['string', false]], $this->columns($aggregator, 'users'));
    }

    /**
     * The migrator runs inherited code too: self:: names the declaring class, while static:: and $this name the migration.
     */
    #[Test]
    public function itAppliesInheritedMigrationCode(): void
    {
        $aggregator = $this->apply(<<<'PHP'
            return new class extends LayeredMigration
            {
                protected $connection = 'mysql';

                protected function createLayer(): void
                {
                    Schema::create('child_layers', fn (Blueprint $table) => $table->string('name'));
                }
            };
            PHP);

        foreach (['base_layers', 'child_layers', 'parts'] as $table) {
            $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, $table));
        }
    }

    /**
     * A method calling the parent method it overrides is not recursion.
     */
    #[Test]
    public function itAppliesParentMethodsTheMigrationOverrides(): void
    {
        $aggregator = $this->apply(<<<'PHP'
            return new class extends LayeredMigration
            {
                protected $connection = 'mysql';

                public function up(): void
                {
                    $this->createWidgets();
                }

                protected function createWidgets(): void
                {
                    parent::createWidgets();

                    Schema::table('widgets', fn (Blueprint $table) => $table->string('color'));
                }
            };
            PHP);

        $this->assertSame(['name' => ['string', false], 'color' => ['string', false]], $this->columns($aggregator, 'widgets'));
    }

    /**
     * PHP runs the private method of the class whose code calls it, not the migration's own.
     */
    #[Test]
    public function itAppliesThePrivateMethodOfTheCallingClass(): void
    {
        $aggregator = $this->apply(<<<'PHP'
            return new class extends LayeredMigration
            {
                protected $connection = 'mysql';

                private function createLedgers(): void
                {
                    Schema::create('child_ledgers', fn (Blueprint $table) => $table->string('name'));
                }
            };
            PHP);

        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'ledgers'));
        $this->assertNull($this->columns($aggregator, 'child_ledgers'));
    }

    /**
     * A recursive call is detected by the method's declaration, whatever parsing returns for its file.
     */
    #[Test]
    public function itStopsAtRecursiveInheritedHelpers(): void
    {
        $aggregator = $this->apply(<<<'PHP'
            return new class extends LayeredMigration
            {
                protected $connection = 'mysql';

                public function up(): void
                {
                    Schema::create('posts', fn (Blueprint $table) => $table->string('title'));

                    $this->createGadgets();
                }
            };
            PHP);

        $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts'));
    }

    /**
     * An inherited helper's builder variables select each calling migration's connection.
     */
    #[Test]
    public function itAppliesInheritedBuilderVariablesOnEachMigrationsConnection(): void
    {
        $aggregator = null;

        foreach (['mysql', 'sqlite'] as $connection) {
            $aggregator = $this->apply(<<<PHP
                return new class extends LayeredMigration
                {
                    protected \$connection = '{$connection}';

                    public function up(): void
                    {
                        \$this->createGizmos();
                    }
                };
                PHP, $aggregator);
        }

        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'gizmos'));
        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'gizmos', 'sqlite'));
    }

    /**
     * A closure, or a callable array naming one of the migration's methods, runs where the analysis cannot follow it.
     */
    #[Test]
    public function itForgetsTablesInheritedHelpersChangeWhereTheyCannotBeFollowed(): void
    {
        foreach ([
            'DB::transaction(fn () => $this->createWidgets());',
            "call_user_func([\$this, 'createWidgets']);",
            "call_user_func([self::class, 'createWidgets']);",
            "call_user_func([static::class, 'createWidgets']);",
            "call_user_func([parent::class, 'createWidgets']);",
        ] as $statement) {
            $aggregator = $this->apply(<<<PHP
                return new class extends LayeredMigration
                {
                    protected \$connection = 'mysql';

                    public function up(): void
                    {
                        Schema::create('widgets', fn (Blueprint \$table) => \$table->string('label'));
                        Schema::create('posts', fn (Blueprint \$table) => \$table->string('title'));

                        {$statement}
                    }
                };
                PHP);

            $this->assertFalse($this->table($aggregator, 'widgets')->complete, $statement);
            $this->assertTrue($this->table($aggregator, 'posts')->complete, $statement);
        }
    }

    /**
     * A dynamic method name can run any of the migration's methods, which can change any connection or the morph key type.
     */
    #[Test]
    public function itForgetsEveryConnectionForADynamicMethodName(): void
    {
        foreach (['$this->{$this->method}();', 'static::{$this->method}();', 'call_user_func([$this, $this->method]);'] as $statement) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::connection('sqlite')->create('posts', fn (Blueprint \$table) => \$table->string('title'));

                {$statement}

                Schema::create('comments', fn (Blueprint \$table) => \$table->morphs('commentable'));
            PHP, members: <<<'PHP'
                public string $method = 'useUuids';

                private function useUuids(): void
                {
                    Schema::morphUsingUuids();
                }
            PHP);

            $this->assertFalse($this->schema($aggregator)->complete, $statement);
            $this->assertFalse($this->schema($aggregator, 'sqlite')->complete, $statement);
            $this->assertSame(['commentable_type' => ['string', false], 'commentable_id' => [null, false]], $this->columns($aggregator, 'comments'), $statement);
        }
    }

    /**
     * A dynamic Schema builder method can change any relation of its connection, or the morph key type.
     */
    #[Test]
    public function itForgetsTheConnectionADynamicSchemaMethodRunsOn(): void
    {
        foreach (["Schema::{\$this->method}('users');", "\$schema = Schema::connection('mysql');\n\$schema->{\$this->method}('users');"] as $statement) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::connection('sqlite')->create('posts', fn (Blueprint \$table) => \$table->string('title'));
                Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));

                {$statement}

                Schema::create('comments', fn (Blueprint \$table) => \$table->morphs('commentable'));
            PHP, members: "public string \$method = 'drop';");

            $this->assertFalse($this->schema($aggregator)->complete, $statement);
            $this->assertTrue($this->schema($aggregator, 'sqlite')->complete, $statement);
            $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts', 'sqlite'), $statement);
            $this->assertSame(['commentable_type' => ['string', false], 'commentable_id' => [null, false]], $this->columns($aggregator, 'comments'), $statement);
        }
    }

    #[Test]
    public function itForgetsEveryConnectionWhenInheritedCodeCannotBeRead(): void
    {
        $aggregator = $this->aggregate("Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));");

        $this->apply(<<<'PHP'
            return new class extends MissingMigration
            {
                public function up(): void
                {
                    $this->createWidgets();
                }
            };
            PHP, $aggregator);

        $this->assertFalse($this->schema($aggregator)->complete);
    }

    /**
     * A trait alias supplies up() or a helper from a method of another name, which is not followed.
     */
    #[Test]
    #[DataProvider('aliasedTraitMethods')]
    public function itForgetsEveryConnectionWhenATraitAliasSuppliesTheMethod(string $members): void
    {
        $aggregator = $this->aggregate("Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));");

        $this->apply(<<<PHP
            return new class extends Migration
            {
                {$members}
            };
            PHP, $aggregator);

        $this->assertFalse($this->schema($aggregator)->complete);
    }

    /** @return iterable<string, array{string}> */
    public static function aliasedTraitMethods(): iterable
    {
        yield 'up' => ['use CreatesParts { createParts as public up; }'];
        yield 'helper' => ['use CreatesParts { createParts as makeParts; } public function up(): void { $this->makeParts(); }'];
    }

    /**
     * Closures run where the analysis cannot follow, so what they change is unknown.
     */
    #[Test]
    public function itForgetsTablesUnfollowedClosuresChange(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('posts', fn (Blueprint $table) => $table->string('title'));

            DB::transaction(fn () => Schema::create('users', fn (Blueprint $table) => $table->id()));
        PHP);

        $this->assertSame([], $this->columns($aggregator, 'users'));
        $this->assertFalse($this->table($aggregator, 'users')->complete);
        $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts'));
        $this->assertTrue($this->schema($aggregator)->complete);
    }

    #[Test]
    public function itIgnoresDiscardedFirstClassCallables(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::table(...);

            Schema::table('users', function (Blueprint $table) {
                $table->string(...);
                $table->string('email')->nullable(...);
            });
        PHP);

        $this->assertSame(['name' => ['string', false], 'email' => ['string', false]], $this->columns($aggregator, 'users'));
        $this->assertTrue($this->table($aggregator, 'users')->complete);
    }

    /**
     * A kept callable can run later where the analysis cannot see it.
     */
    #[Test]
    public function itForgetsWhatKeptFirstClassCallablesCanChange(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));
            Schema::create('posts', fn (Blueprint $table) => $table->string('title'));

            Schema::table('users', function (Blueprint $table) {
                $add = $table->string(...);
            });
        PHP);

        $this->assertSame([], $this->columns($aggregator, 'users'));
        $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts'));

        foreach (['$create = Schema::create(...);', '$run = DB::statement(...);'] as $statement) {
            $aggregator = $this->aggregate(<<<PHP
                Schema::create('posts', fn (Blueprint \$table) => \$table->string('title'));

                {$statement}
            PHP);

            $this->assertNull($this->columns($aggregator, 'posts'), $statement);
            $this->assertFalse($this->schema($aggregator)->complete, $statement);
        }
    }

    /**
     * Only MySQL reads the sign, so an unknown sign only makes its column's type unknown there.
     */
    #[Test]
    public function itForgetsTheTypeOfAColumnWithAnUnknownSign(): void
    {
        foreach (['mysql' => [null, false], 'pgsql' => ['int', false]] as $connection => $expected) {
            $aggregator = $this->aggregate(<<<'PHP'
                Schema::create('users', function (Blueprint $table) {
                    $table->string('name');
                    $table->integer('votes')->unsigned($this->unsigned);
                });
            PHP, $connection, members: 'public bool $unsigned = true;');

            $this->assertSame(['name' => ['string', false], 'votes' => $expected], $this->columns($aggregator, 'users', $connection), $connection);
        }
    }

    /**
     * Like raw CREATE EXTENSION, an extension can create relations the analysis does not know.
     */
    #[Test]
    public function itForgetsTheConnectionAnExtensionIsEnsuredOn(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::ensureExtensionExists('vector');
        PHP, 'pgsql');

        $this->assertNull($this->columns($aggregator, 'users', 'pgsql'));
        $this->assertFalse($this->schema($aggregator, 'pgsql')->complete);
    }

    #[Test]
    public function itAppliesStatementsToTheConnectionTheyRunOn(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('users', fn (Blueprint $table) => $table->string('name'));

            Schema::connection('sqlite')->create('posts', fn (Blueprint $table) => $table->string('title'));
        PHP, 'pgsql');

        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'users', 'pgsql'));
        $this->assertNull($this->columns($aggregator, 'posts', 'pgsql'));
        $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts', 'sqlite'));
    }

    /**
     * A migration that chooses its connection in a way the analysis cannot read could change any connection.
     *
     * @param array<string, string> $config
     */
    #[Test]
    #[DataProvider('connectionMethods')]
    public function itEvaluatesTheConnectionGetConnectionReturns(string $method, array $config, ?string $expected): void
    {
        $aggregator = $this->withConfig($config, fn (): SchemaAggregator => $this->aggregate(<<<'PHP'
            Schema::create('posts', fn (Blueprint $table) => $table->string('title'));
        PHP, null, $this->aggregate("Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));"), $method));

        if ($expected === null) {
            $this->assertNull($this->columns($aggregator, 'users'));
            $this->assertFalse($this->schema($aggregator)->complete);
        } else {
            $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'users'));
            $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts', $expected === 'default' ? config('database.default') : $expected));
        }
    }

    /**
     * @return iterable<string, array{string, array<string, string>, ?string}> the getConnection() method, the config
     *                                                                         values and the connection, null when unknown
     */
    public static function connectionMethods(): iterable
    {
        $coalesce = "public function getConnection(): ?string { return \$this->connection ?? config('schema_aggregator.connection'); }";
        $typed = "public function getConnection(): string { return config()->string('schema_aggregator.connection'); }";

        yield 'property or configured connection' => [$coalesce, ['schema_aggregator.connection' => 'pgsql'], 'pgsql'];
        yield 'property or missing config' => [$coalesce, [], 'default'];
        yield 'typed configured connection' => [$typed, ['schema_aggregator.connection' => 'pgsql'], 'pgsql'];
        yield 'typed missing config' => [$typed, [], null];
        yield 'facade default' => ["public function getConnection(): ?string { return Config::get('schema_aggregator.connection', 'pgsql'); }", [], 'pgsql'];
        yield 'null for a string return type' => ["public function getConnection(): string { return config('schema_aggregator.connection'); }", [], null];
        yield 'unreadable' => ['public function getConnection(): ?string { return $this->name(); }', [], null];
    }

    /**
     * A connection property the migration sets at runtime could select any connection, unless only down() sets it.
     */
    #[Test]
    public function itForgetsTheConnectionAMigrationSetsAtRuntime(): void
    {
        $migrations = [
            'constructor' => ['protected $connection = null; public function __construct() { $this->connection = config("schema_aggregator.connection"); }', ''],
            'up' => ['protected $connection = null;', '$this->connection ??= "sqlite";'],
            'promoted' => ['public function __construct(protected ?string $connection = null) {}', ''],
            'trait' => ['use SelectsConnection;', ''],
            'down only' => ['protected $connection = null; public function down(): void { $this->connection = "sqlite"; }', ''],
        ];

        foreach ($migrations as $case => [$members, $up]) {
            $aggregator = $this->apply(<<<PHP
                return new class extends Migration
                {
                    {$members}

                    public function up(): void
                    {
                        {$up}
                        Schema::create('posts', fn (Blueprint \$table) => \$table->string('title'));
                    }
                };
            PHP, $this->aggregate("Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));", config('database.default')));

            $known = $case === 'down only';

            $this->assertSame($known, $this->schema($aggregator, config('database.default'))->complete, $case);
            $this->assertSame($known ? ['title' => ['string', false]] : null, $this->columns($aggregator, 'posts', config('database.default')), $case);
        }
    }

    /**
     * A class between the migration and Migration, or a trait the migration uses, can choose the connection in a
     * way the analysis does not read.
     */
    #[Test]
    public function itReadsOnlyTheConnectionInheritedFromMigration(): void
    {
        $migrations = [
            'Migration' => ['Migration', ''],
            'ConnectionPropertyMigration' => ['ConnectionPropertyMigration', ''],
            'ConnectionMethodMigration' => ['ConnectionMethodMigration', ''],
            'ConnectionWritingMigration' => ['ConnectionWritingMigration', ''],
            'trait method' => ['Migration', 'use ChoosesConnection;'],
            'nested trait method' => ['Migration', 'use NestedChoosesConnection;'],
            'trait property' => ['Migration', 'use DefinesConnection;'],
            'trait alias' => ['Migration', 'use NamesConnection { connectionName as getConnection; }'],
        ];

        foreach ($migrations as $case => [$parent, $members]) {
            $aggregator = $this->apply(<<<PHP
                return new class extends {$parent}
                {
                    {$members}

                    public function up(): void
                    {
                        Schema::create('posts', fn (Blueprint \$table) => \$table->string('title'));
                    }
                };
            PHP, $this->aggregate("Schema::create('users', fn (Blueprint \$table) => \$table->string('name'));", config('database.default')));

            $known = $case === 'Migration';

            $this->assertSame($known, $this->schema($aggregator, config('database.default'))->complete, $case);
            $this->assertSame($known ? ['title' => ['string', false]] : null, $this->columns($aggregator, 'posts', config('database.default')), $case);
        }
    }

    /**
     * Calls on a variable holding a builder are followed when every write selects one connection and
     * every other use calls a method on it.
     */
    #[Test]
    public function itFollowsSchemaBuildersHeldInVariables(): void
    {
        $aggregator = $this->withConfig(['schema_aggregator.connection' => 'pgsql'], fn (): SchemaAggregator => $this->aggregate(<<<'PHP'
            $schema = Schema::connection($this->getConnection());

            $schema->create('users', fn (Blueprint $table) => $table->string('name'));

            if (! $schema->hasTable('users')) {
                $schema->create('skipped', fn (Blueprint $table) => $table->string('name'));
            }

            $builder = DB::connection('sqlite')->getSchemaBuilder();
            $builder->create('posts', fn (Blueprint $table) => $table->string('title'));

            DB::connection('sqlite')->table('posts')->update(['title' => 'Draft']);
        PHP, members: <<<'PHP'
            public function getConnection(): string
            {
                return config()->string('schema_aggregator.connection');
            }
        PHP));

        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'users', 'pgsql'));
        $this->assertNull($this->columns($aggregator, 'skipped', 'pgsql'));
        $this->assertTrue($this->schema($aggregator, 'pgsql')->complete);
        $this->assertSame(['title' => ['string', false]], $this->columns($aggregator, 'posts', 'sqlite'));
    }

    /**
     * A builder or connection that escapes can change its connection wherever it is used.
     */
    #[Test]
    public function itForgetsTheConnectionsEscapedSelectorsReach(): void
    {
        $create = "Schema::create('accounts', fn (Blueprint \$table) => \$table->string('name'));\n"
            . "Schema::connection('pgsql')->create('users', fn (Blueprint \$table) => \$table->string('name'));";
        $helper = 'private function prepare(mixed $schema): void {}';

        foreach ([
            'argument' => "\$schema = Schema::connection('pgsql');\n\$this->prepare(\$schema);",
            'two connections' => "\$schema = Schema::connection('pgsql');\n\$schema = Schema::connection('sqlite');",
            'database connection' => "\$db = DB::connection('pgsql');",
            'kept callable' => '$select = Schema::connection(...);',
        ] as $case => $statements) {
            $aggregator = $this->aggregate("{$create}\n{$statements}", members: $helper);

            $this->assertNull($this->columns($aggregator, 'users', 'pgsql'), $case);
            $this->assertSame($case === 'kept callable', ! $this->schema($aggregator)->complete, $case);
        }

        // A later use forgets what the connection established since the selector escaped.
        foreach (["\$schema->drop('posts');", '$this->prepare($schema);'] as $use) {
            $aggregator = $this->aggregate(<<<PHP
                \$schema = Schema::connection('pgsql');
                \$this->prepare(\$schema);

                Schema::connection('pgsql')->create('posts', fn (Blueprint \$table) => \$table->string('title'));
                {$use}
            PHP, members: $helper);

            $this->assertNull($this->columns($aggregator, 'posts', 'pgsql'), $use);
        }

        // A closure that captures the variable uses it too.
        $aggregator = $this->aggregate(<<<'PHP'
            $schema = Schema::connection('pgsql');

            Schema::connection('pgsql')->create('users', fn (Blueprint $table) => $table->string('name'));
            Schema::withoutForeignKeyConstraints(function () use ($schema) {
                $schema->drop('users');
            });
        PHP);

        $this->assertNull($this->columns($aggregator, 'users', 'pgsql'));
    }

    #[Test]
    public function itFollowsTheMorphKeyType(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::morphUsingUuids();
            Schema::create('images', fn (Blueprint $table) => $table->morphs('imageable'));

            Schema::defaultMorphKeyType($this->type);
            Schema::create('videos', fn (Blueprint $table) => $table->morphs('videoable'));
        PHP, members: "public string \$type = 'int';");

        $this->assertSame(['imageable_type' => ['string', false], 'imageable_id' => ['string', false]], $this->columns($aggregator, 'images'));
        $this->assertSame(['videoable_type' => ['string', false], 'videoable_id' => [null, false]], $this->columns($aggregator, 'videos'));
    }

    /**
     * PostgreSQL applies ALTER TABLE without ONLY to the inheritance and partition descendants too.
     */
    #[Test]
    public function itForgetsTheDescendantsOfAChangedPostgresTable(): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            DB::unprepared(<<<'SQL'
                CREATE TABLE parents (id int);
                CREATE TABLE children (age int) INHERITS (parents);
                CREATE TABLE others (id int);
                CREATE TABLE measurements (taken date) PARTITION BY RANGE (taken);
                CREATE TABLE measurements_2026 PARTITION OF measurements FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
            SQL);

            DB::statement('ALTER TABLE parents ALTER COLUMN id TYPE bigint');

            Schema::table('measurements', fn (Blueprint $table) => $table->string('unit'));
        PHP, 'pgsql');

        $this->assertSame([], $this->columns($aggregator, 'parents', 'pgsql'));
        $this->assertSame([], $this->columns($aggregator, 'children', 'pgsql'));
        $this->assertFalse($this->table($aggregator, 'children', 'pgsql')->complete);
        $this->assertSame(['id' => ['int', true]], $this->columns($aggregator, 'others', 'pgsql'));
        $this->assertSame(['taken' => ['string', true], 'unit' => ['string', false]], $this->columns($aggregator, 'measurements', 'pgsql'));
        $this->assertSame([], $this->columns($aggregator, 'measurements_2026', 'pgsql'));
    }

    /**
     * An altered or dropped type makes only that type uncertain, which stops its search along the path
     * and is not bypassed through the base a domain column embeds. A type dropped from a schema off
     * the path cannot be one defined without a schema.
     */
    #[Test]
    public function itMakesOnlyTheUserTypesRawSqlChangesUncertain(): void
    {
        $aggregator = $this->withSearchPath(<<<'PHP'
            DB::unprepared(<<<'SQL'
                CREATE TYPE a.status AS ENUM ('old');
                CREATE TYPE b.status AS ENUM ('new');
                CREATE TYPE mood AS ENUM ('happy', 'sad');
                CREATE TYPE feeling AS ENUM ('good');
                CREATE DOMAIN a.code AS integer;
                CREATE DOMAIN a.tags AS integer[];
                CREATE TABLE a.people (mood mood NOT NULL, feeling feeling NOT NULL, code code NOT NULL, codes code[] NOT NULL, tags tags NOT NULL);
                CREATE TABLE a.others (name text NOT NULL);
            SQL);

            DB::statement('DROP TYPE a.status');
            DB::statement('CREATE TABLE b.items (status status NOT NULL)');
            DB::statement("ALTER TYPE a.mood ADD VALUE 'calm'");
            DB::statement('DROP TYPE c.feeling');
            DB::statement('ALTER DOMAIN code SET NOT NULL');
        PHP);

        $this->assertSame(['status' => ['mixed', false]], $this->columns($aggregator, 'b.items', 'pgsql_paths'));
        $this->assertSame([
            'mood' => ['mixed', false],
            'feeling' => ["'good'", false],
            'code' => ['mixed', false],
            'codes' => ['string', false],
            'tags' => ['string', false],
        ], $this->columns($aggregator, 'a.people', 'pgsql_paths'));
        $this->assertSame(['name' => ['string', false]], $this->columns($aggregator, 'a.others', 'pgsql_paths'));
        $this->assertTrue($this->schema($aggregator, 'pgsql_paths')->complete);
    }

    /**
     * A type that raw SQL with issues defines, or that one outcome defines, may exist in its schema.
     */
    #[Test]
    public function itStopsTheTypeSearchAtAnUncertainDefinition(): void
    {
        $aggregator = $this->withSearchPath(<<<'PHP'
            DB::unprepared(<<<'SQL'
                CREATE TYPE b.status AS ENUM ('new');
                CREATE TYPE b.level AS ENUM ('low');
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TYPE a.status AS ENUM ('draft');
                ALTER TYPE a.status ADD VALUE 'final';
            SQL);

            if ($this->flag) {
                DB::statement("CREATE TYPE a.level AS ENUM ('high')");
            }

            DB::statement('CREATE TABLE b.items (status status NOT NULL, level level NOT NULL)');
        PHP, members: 'public bool $flag = true;');

        $this->assertSame([
            'status' => ['mixed', false],
            'level' => ['mixed', false],
        ], $this->columns($aggregator, 'b.items', 'pgsql_paths'));
    }

    /**
     * @param array<string, array{?string, bool}> $expected
     */
    #[Test]
    #[DataProvider('blueprintTypes')]
    public function itMapsBlueprintTypesToTheTypesEachGrammarCreates(string $connection, array $expected): void
    {
        $aggregator = $this->aggregate(<<<'PHP'
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('active');
                $table->decimal('price', 8, 2);
                $table->json('meta');
                $table->uuid('token');
                $table->enum('status', ['draft', 'published']);
                $table->timestamp('created_at')->nullable();
            });
        PHP, $connection);

        $this->assertSame($expected, $this->columns($aggregator, 'orders', $connection));
    }

    /** @return iterable<string, array{string, array<string, array{?string, bool}>}> */
    public static function blueprintTypes(): iterable
    {
        yield 'mysql' => ['mysql', [
            'id' => ['int<0, max>|numeric-string', false],
            'name' => ['string', false],
            'active' => ['int', false],
            'price' => ['numeric-string', false],
            'meta' => ['string', false],
            'token' => ['string', false],
            'status' => ["'draft'|'published'", false],
            'created_at' => ['string', true],
        ]];
        yield 'pgsql' => ['pgsql', [
            'id' => ['int', false],
            'name' => ['string', false],
            'active' => ['bool', false],
            'price' => ['string', false],
            'meta' => ['string', false],
            'token' => ['string', false],
            'status' => ["'draft'|'published'", false],
            'created_at' => ['string', true],
        ]];
        yield 'sqlite' => ['sqlite', [
            'id' => ['int', false],
            'name' => ['string', false],
            'active' => ['int', false],
            'price' => ['float|int', false],
            'meta' => ['string', false],
            'token' => ['string', false],
            'status' => ["'draft'|'published'", false],
            'created_at' => ['string', true],
        ]];
    }

    /**
     * SQLite fetches a JSON number from a json or jsonb column as a PHP number.
     */
    #[Test]
    public function itReadsNumbersFromNativeSqliteJsonColumns(): void
    {
        $sqlite = config('database.connections.sqlite');

        $aggregator = $this->withConfig(
            ['database.connections.native_json' => [...$sqlite, 'use_native_json' => true, 'use_native_jsonb' => true]],
            fn (): SchemaAggregator => $this->aggregate(<<<'PHP'
                Schema::create('orders', function (Blueprint $table) {
                    $table->json('meta');
                    $table->jsonb('options');
                });
            PHP, 'native_json'),
        );

        $this->assertSame(
            ['meta' => ['float|int|string', false], 'options' => ['float|int|string', false]],
            $this->columns($aggregator, 'orders', 'native_json'),
        );
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }

    /**
     * Apply a migration with the given up() body, connection property and optionally other members, to the aggregator.
     */
    private function aggregate(string $body, ?string $connection = 'mysql', ?SchemaAggregator $aggregator = null, string $members = ''): SchemaAggregator
    {
        $property = $connection === null ? 'null' : "'{$connection}'";

        return $this->apply(<<<PHP
            return new class extends Migration
            {
                protected \$connection = {$property};

                public function up(): void
                {
            {$body}
                }

            {$members}
            };
            PHP, $aggregator);
    }

    /**
     * Apply a migration file's statements, in the fixture namespace with its imports, to the aggregator.
     */
    private function apply(string $statements, ?SchemaAggregator $aggregator = null): SchemaAggregator
    {
        /** @var Parser $parser */
        $parser = self::getContainer()->getService('currentPhpVersionSimpleDirectParser');

        $aggregator ??= new SchemaAggregator(
            $this->createReflectionProvider(),
            self::getContainer()->getByType(InitializerExprTypeResolver::class),
            self::getContainer()->getByType(ConnectionConfigResolver::class),
            $parser,
        );

        $aggregator->addStatements($parser->parseString(<<<PHP
            <?php

            namespace Hypervel\\Tests\\PHPStan\\Fixtures\\SchemaAggregator;

            use Hypervel\\Database\\Migrations\\Migration;
            use Hypervel\\Database\\Schema\\Blueprint;
            use Hypervel\\Support\\Facades\\Config;
            use Hypervel\\Support\\Facades\\DB;
            use Hypervel\\Support\\Facades\\Schema;

            {$statements}
            PHP));

        return $aggregator;
    }

    /**
     * Aggregate a migration body on the PostgreSQL connection pgsql_paths, whose search path is a, then b.
     */
    private function withSearchPath(string $body, string $members = ''): SchemaAggregator
    {
        return $this->withConfig(
            ['database.connections.pgsql_paths' => ['driver' => 'pgsql', 'search_path' => 'a,b']],
            fn (): SchemaAggregator => $this->aggregate($body, 'pgsql_paths', members: $members),
        );
    }

    /**
     * Get the files declaring the given classes.
     *
     * @param class-string ...$classes
     * @return list<string>
     */
    private function fileNames(string ...$classes): array
    {
        return array_map(static fn (string $class): string => (string) (new ReflectionClass($class))->getFileName(), $classes);
    }

    /**
     * Get the facts of the connection a connection name's migrations run on.
     */
    private function schema(SchemaAggregator $aggregator, string $connection = 'mysql'): SchemaConnection
    {
        $schema = $this->findSchema($aggregator->connections, $connection);

        $this->assertNotNull($schema);

        return $schema;
    }

    /**
     * Get the facts of a table, failing when it is not known.
     */
    private function table(SchemaAggregator $aggregator, string $table, string $connection = 'mysql'): SchemaTable
    {
        $tableFacts = $this->findTable($aggregator->connections, $table, $connection);

        $this->assertNotNull($tableFacts, "The table [{$table}] is not known.");

        return $tableFacts;
    }

    /**
     * Get a table's columns as their read types (null when unknown) and nullability, or null when the table is not known.
     *
     * @return null|array<string, array{?string, bool}>
     */
    private function columns(SchemaAggregator $aggregator, string $table, string $connection = 'mysql'): ?array
    {
        return $this->describeColumns($aggregator->connections, $table, $connection);
    }
}
