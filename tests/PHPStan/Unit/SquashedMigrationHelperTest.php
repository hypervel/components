<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\MigrationHelper;
use Hypervel\PHPStan\Properties\SquashedMigrationHelper;
use Hypervel\Tests\PHPStan\Fixtures\ReadsSchemaFacts;
use PHPStan\File\FileHelper;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function config;
use function database_path;
use function in_array;

/**
 * Unlike upstream, a dump belongs to the connection its name gives, and columns are described
 * by the types that connection fetches.
 */
class SquashedMigrationHelperTest extends PHPStanTestCase
{
    use ReadsSchemaFacts;

    /** The columns of the six-column accounts and users dumps. */
    private const array ACCOUNT_COLUMNS = [
        'id' => ['int<0, max>|numeric-string', false],
        'name' => ['string', false],
        'active' => ['string', false],
        'description' => ['string', true],
        'created_at' => ['string', true],
        'updated_at' => ['string', true],
    ];

    #[Test]
    public function itCanParseSchemaDumpForABasicSchema(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/basic_schema'])->initializeConnections();

        $this->assertSame(['mysql'], array_keys($connections));
        $this->assertCount(1, $connections['mysql']->tables);
        $this->assertSame([
            'id' => ['int<0, max>|numeric-string', false],
            'name' => ['string', false],
            'active' => ['string', false],
            'description' => ['string', true],
            'notes' => ['string', true],
            'profile_text' => ['string', false],
            'balance' => ['float', true],
            'label' => ['string', true],
            'enum_status' => ["'a very long string that exceeds the ConstantStringType DESCRIBE_LIMIT'|'active'|'don\\'t know'|'inactive'", false],
            'created_at' => ['string', true],
            'updated_at' => ['string', true],
        ], $this->describeColumns($connections, 'accounts', 'mysql'));
    }

    /**
     * Unlike upstream, which kept the first file's table, only a connection's .dump file is
     * read when it also has a .sql file, as in MigrateCommand::schemaPath().
     */
    #[Test]
    public function itReadsTheDumpFileOfAConnectionWithASqlFileToo(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/multiple_schemas_for_same_table'])->initializeConnections();

        $this->assertSame(['mysql'], array_keys($connections));
        $this->assertSame(self::ACCOUNT_COLUMNS, $this->describeColumns($connections, 'accounts', 'mysql'));
    }

    /**
     * Two dumps of one connection, such as in two schema directories, give no single definition.
     */
    #[Test]
    public function itForgetsATableThatTwoDumpsOfOneConnectionDefine(): void
    {
        $connections = $this->createHelper([
            __DIR__ . '/data/schema/basic_schema',
            __DIR__ . '/data/schema/multiple_schemas_for_same_table',
        ])->initializeConnections();

        $table = $this->findTable($connections, 'accounts', 'mysql');

        $this->assertNotNull($table);
        $this->assertSame([], $table->columns);
        $this->assertFalse($table->complete);
        $this->assertTrue($connections['mysql']->complete);
    }

    #[Test]
    public function itCanFindSchemasWithSqlSuffix(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/basic_schema_with_sql_extension'])->initializeConnections();

        $this->assertSame(['mysql'], array_keys($connections));
        $this->assertSame(self::ACCOUNT_COLUMNS, $this->describeColumns($connections, 'accounts', 'mysql'));
    }

    #[Test]
    public function itCanFindSchemasWithDifferentExtensions(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/multiple_schemas_with_different_extensions'])->initializeConnections();

        $this->assertSame(['mysql', 'mariadb'], array_keys($connections));
        $this->assertSame(self::ACCOUNT_COLUMNS, $this->describeColumns($connections, 'accounts', 'mysql'));
        $this->assertSame(self::ACCOUNT_COLUMNS, $this->describeColumns($connections, 'users', 'mariadb'));
    }

    /**
     * Unlike upstream, which skipped a dump it could not parse, the dump's connection is incomplete
     * and the dump is recorded for diagnosis.
     */
    #[Test]
    public function itRecordsDumpsThatCannotBeParsed(): void
    {
        $directory = __DIR__ . '/data/schema/unusable_dumps';
        $connections = $this->createHelper([$directory])->initializeConnections();

        $this->assertSame([], $connections['mysql']->tables);
        $this->assertFalse($connections['mysql']->complete);
        $this->assertSame(
            [['path' => $directory . '/mysql-schema.sql', 'line' => 3, 'message' => 'Unterminated string literal at SQL line 3']],
            $connections['mysql']->unusableDumps,
        );
        $this->assertSame([], $connections['pgsql']->tables);
        $this->assertFalse($connections['pgsql']->complete);
        $this->assertSame(
            [['path' => $directory . '/pgsql-schema.dump', 'line' => 1, 'message' => 'PostgreSQL archives must be exported as plain SQL before parsing.']],
            $connections['pgsql']->unusableDumps,
        );
    }

    /**
     * A schema-level issue means represented definitions may be wrong.
     */
    #[Test]
    public function itDoesNotUseTheTypesOfADumpWithIssues(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/pgsql_with_issues'])->initializeConnections();

        $this->assertFalse($connections['pgsql']->complete);
        $this->assertFalse($this->findTable($connections, 'accounts', 'pgsql')?->complete);
        $this->assertSame([
            'id' => ['int', false],
            'status' => ['mixed', false],
        ], $this->describeColumns($connections, 'accounts', 'pgsql'));
        $this->assertSame([], $connections['pgsql']->unusableDumps);
    }

    /**
     * An alteration the parser could not apply can leave a removed column behind, so its table is unknown.
     */
    #[Test]
    public function itForgetsATableWithAnAlterationThatCouldNotBeApplied(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/unapplied_alteration'])->initializeConnections();

        $this->assertSame([], $this->describeColumns($connections, 'users', 'sqlite'));
        $this->assertFalse($this->findTable($connections, 'users', 'sqlite')?->complete);
        $this->assertSame(['id' => ['int', true], 'title' => ['string', true]], $this->describeColumns($connections, 'posts', 'sqlite'));
    }

    #[Test]
    public function itForgetsATableWithACascadingAlteration(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/pgsql_cascading_alteration'])->initializeConnections();

        $this->assertFalse($connections['pgsql']->complete);
        $this->assertSame([], $this->describeColumns($connections, 'users', 'pgsql'));
        $this->assertFalse($this->findTable($connections, 'users', 'pgsql')?->complete);
        $this->assertSame(['id' => ['int', false]], $this->describeColumns($connections, 'posts', 'pgsql'));
    }

    #[Test]
    public function itResolvesUserTypesAndKeepsViewsUnknown(): void
    {
        $connections = $this->createHelper([__DIR__ . '/data/schema/pgsql_types_and_views'])->initializeConnections();

        $this->assertTrue($connections['pgsql']->complete);
        $this->assertSame([
            'id' => ['int', false],
            'status' => ["'active'|'inactive'", false],
        ], $this->describeColumns($connections, 'accounts', 'pgsql'));
        $this->assertSame([], $this->describeColumns($connections, 'active_accounts', 'pgsql'));
        $this->assertFalse($this->findTable($connections, 'active_accounts', 'pgsql')?->complete);
    }

    /**
     * A type that two dumps of one connection define differently has no single definition, even
     * for a column that embeds its domain's base. An identical definition stays known.
     */
    #[Test]
    public function itMakesTypesTwoDumpsDefineDifferentlyUncertain(): void
    {
        $connections = $this->createHelper([
            __DIR__ . '/data/schema/pgsql_types_and_views',
            __DIR__ . '/data/schema/pgsql_conflicting_types',
        ])->initializeConnections();

        $this->assertSame([
            'status' => ['mixed', false],
            'level' => ["'high'|'low'", false],
            'code' => ['mixed', false],
        ], $this->describeColumns($connections, 'events', 'pgsql'));
    }

    #[Test]
    #[DataProvider('fixtureConnections')]
    public function itReadsTheFixtureApplicationDumpsAsItsMigrationsDefineThem(string $connection): void
    {
        // The fixture application's dumps are real schema:dump output of its migrations, so the
        // Blueprint mapping must give each table the types the driver's grammar created.
        $default = config('database.default');
        config(['database.default' => $connection]);

        try {
            $migrations = (new MigrationHelper(
                self::getContainer()->getService('currentPhpVersionSimpleDirectParser'),
                [database_path('migrations')],
                self::getContainer()->getByType(FileHelper::class),
                false,
                self::getContainer()->getByType(ReflectionProvider::class),
                self::getContainer()->getByType(InitializerExprTypeResolver::class),
                self::getContainer()->getByType(ConnectionConfigResolver::class),
            ))->initializeConnections();
        } finally {
            config(['database.default' => $default]);
        }

        $dumps = $this->createHelper([])->initializeConnections();
        $fromMigrations = [];
        $fromDumps = [];

        foreach ($this->findSchema($migrations, $connection)->tables as $table) {
            $fromMigrations[$table->name] = $this->describeColumns($migrations, $table->name, $connection);
            $fromDumps[$table->name] = $this->describeColumns($dumps, $table->name, $connection);
        }

        // rawColumn() reads mixed from a migration, while the dump records the type it created.
        $this->assertSame([null, false], $fromMigrations['users']['unknown_column']);
        $fromMigrations['users']['unknown_column'] = ['string', false];

        if (in_array($connection, ['pgsql', 'sqlite'], true)) {
            // A Blueprint enum is a CHECK constraint on these drivers; only the migration knows its labels.
            $this->assertSame(["'active'|'don\\'t know'|'inactive'", false], $fromMigrations['users']['enum_status']);
            $fromMigrations['users']['enum_status'] = ['string', false];
        }

        $this->assertSame($fromMigrations, $fromDumps);
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureConnections(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
        yield 'pgsql' => ['pgsql'];
        yield 'sqlite' => ['sqlite'];
    }

    #[Test]
    public function itCanDisableSchemaScanning(): void
    {
        $this->assertSame([], $this->createHelper([__DIR__ . '/data/schema/multiple_schemas_with_different_extensions'], true)->initializeConnections());
    }

    #[Test]
    public function itListsTheDefaultSchemaFilesBeforeTheTablesAreInitialized(): void
    {
        $schemaParser = $this->createHelper([]);

        $this->assertSame([database_path('schema')], $schemaParser->getSchemaDirectories());
        $this->assertNotSame([], $schemaParser->getSchemaFiles());
    }

    #[Test]
    public function itKeepsASchemaDirectoryThatDoesNotExist(): void
    {
        $fileHelper = self::getContainer()->getByType(FileHelper::class);

        $this->assertSame(
            [$fileHelper->absolutizePath(__DIR__ . '/data/schema/missing')],
            $this->createHelper([__DIR__ . '/data/schema/missing'])->getSchemaDirectories(),
        );
        $this->assertSame([], $this->createHelper([__DIR__ . '/data/schema/missing'])->getSchemaFiles());
    }

    #[Test]
    public function itHasNoSchemaDirectoriesWhenTheSchemaScanIsDisabled(): void
    {
        $this->assertSame([], $this->createHelper([__DIR__ . '/data/schema/basic_schema'], true)->getSchemaDirectories());
    }

    /** @param string[] $schemaPaths */
    private function createHelper(array $schemaPaths, bool $disableSchemaScan = false): SquashedMigrationHelper
    {
        return new SquashedMigrationHelper(
            $schemaPaths,
            self::getContainer()->getByType(FileHelper::class),
            self::getContainer()->getByType(ConnectionConfigResolver::class),
            $disableSchemaScan,
        );
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
