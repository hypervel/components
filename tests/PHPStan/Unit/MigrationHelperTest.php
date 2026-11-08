<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\MigrationHelper;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\Tests\PHPStan\Fixtures\ReadsSchemaFacts;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unlike upstream, the facts belong to the connection each migration runs on, the routed default
 * connection here (SQLite), and columns are described by the types that connection fetches.
 */
class MigrationHelperTest extends PHPStanTestCase
{
    use ReadsSchemaFacts;

    private Parser $parser;

    private FileHelper $fileHelper;

    private ReflectionProvider $reflectionProvider;

    private InitializerExprTypeResolver $initializerExprTypeResolver;

    private ConnectionConfigResolver $connectionConfigResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = self::getContainer()->getService('currentPhpVersionSimpleDirectParser');
        $this->fileHelper = self::getContainer()->getByType(FileHelper::class);
        $this->reflectionProvider = $this->createReflectionProvider();
        $this->initializerExprTypeResolver = self::getContainer()->getByType(InitializerExprTypeResolver::class);
        $this->connectionConfigResolver = self::getContainer()->getByType(ConnectionConfigResolver::class);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }

    #[Test]
    public function itKeepsAMigrationDirectoryThatDoesNotExist(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/missing'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        self::assertSame([$this->fileHelper->absolutizePath(__DIR__ . '/data/missing') => '*.[pP][hH][pP]'], $migrationHelper->getMigrationPaths());
        self::assertSame([], $migrationHelper->getMigrationFiles());
    }

    #[Test]
    public function itHasNoMigrationDirectoriesWhenTheMigrationScanIsDisabled(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/basic_migration'], $this->fileHelper, true, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        self::assertSame([], $migrationHelper->getMigrationPaths());
    }

    #[Test]
    public function itWillReturnEmptyArrayIfMigrationsPathIsNotADirectory(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, ['foobar'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        self::assertSame([], $migrationHelper->initializeConnections());
    }

    #[Test]
    public function itCanReadBasicMigrationsAndCreateTableStructure(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/basic_migration'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanReadSchemaDefinitionsFromAnyMethodInClass(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migrations_with_different_methods'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanReadSchemaDefinitionsWithMultipleCreateAndDropMethodsForOneTable(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/complex_migrations'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertSame([
            'id' => ['int', false],
            'email' => ['string', false],
            'birthday' => ['string', false],
            'city' => ['string', true],
            'zip' => ['int', false],
            'created_at' => ['string', true],
            'updated_at' => ['string', true],
            'active' => ['int', false],
        ], $this->describeColumns($connections, 'users'));
    }

    #[Test]
    public function itCanReadAdditionalDirectories(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/basic_migration',
            __DIR__ . '/data/additional_migrations',
        ], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(2, $this->findSchema($connections)->tables);
        self::assertNotNull($this->findTable($connections, 'users'));
        self::assertNotNull($this->findTable($connections, 'teams'));
    }

    #[Test]
    public function itCanHandleUseOfAfterMethodInMigration(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/migrations_using_after_method',
        ], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertSame([
            'id' => ['int', false],
            'name' => ['string', true],
            'created_at' => ['string', true],
            'updated_at' => ['string', true],
            'email' => ['string', false],
        ], $this->describeColumns($connections, 'users'));
    }

    #[Test]
    public function itCanHandleAlterTableAndColumnRename(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/rename_migrations',
        ], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertNull($this->findTable($connections, 'users'));
        $columns = $this->describeColumns($connections, 'accounts');
        self::assertArrayNotHasKey('name', $columns);
        self::assertSame(['string', true], $columns['full_name']);
    }

    #[Test]
    public function itCanHandleMigrationsWithSoftDeletes(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migrations_using_soft_deletes'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertCount(6, $this->describeColumns($connections, 'users'));
        self::assertSame(['string', true], $this->describeColumns($connections, 'users')['deleted_at']);
    }

    #[Test]
    public function itCanHandleMigrationsWithSoftDeletesTz(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migrations_using_soft_deletes_tz'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertCount(6, $this->describeColumns($connections, 'users'));
        self::assertSame(['string', true], $this->describeColumns($connections, 'users')['deleted_at']);
    }

    #[Test]
    public function itCanHandleMigrationsWithNullableTimestampsTz(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migrations_using_nullable_timestamps_tz'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanHandleMigrationsWithDefaultArguments(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migration_with_default_arguments'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertSame([
            'id' => ['int', false],
            'ip_address' => ['string', false],
            'custom_ip_address' => ['string', false],
            'mac_address' => ['string', false],
            'custom_mac_address' => ['string', false],
            'uuid' => ['string', false],
            'custom_uuid' => ['string', false],
            'ulid' => ['string', false],
            'custom_ulid' => ['string', false],
            'deleted_at' => ['string', true],
            'custom_soft_deletes' => ['string', true],
        ], $this->describeColumns($connections, 'users'));
    }

    /**
     * Unlike upstream, which collected tables regardless of connection, the facts belong to the selected
     * connection, so the fixture selects a configured one.
     */
    #[Test]
    public function itCanHandleConnectionBeforeSchemaCreate(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migration_with_schema_connection'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertNull($this->findSchema($connections));
        self::assertCount(1, $this->findSchema($connections, 'mysql')->tables);
        self::assertSame([
            'id' => ['int<0, max>|numeric-string', false],
            'name' => ['string', true],
            'email' => ['string', false],
            'created_at' => ['string', true],
            'updated_at' => ['string', true],
        ], $this->describeColumns($connections, 'users', 'mysql'));
    }

    #[Test]
    public function itCanDisableMigrationScanning(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/basic_migration',
            __DIR__ . '/data/additional_migrations',
        ], $this->fileHelper, true, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertSame([], $connections);
    }

    #[Test]
    public function itCanHandleNullableInMigrations(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migrations_using_nullable'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $columns = $this->describeColumns($migrationHelper->initializeConnections(), 'users');

        self::assertSame(['string', false], $columns['name']);
        self::assertSame(['string', true], $columns['email']);
        self::assertSame(['string', true], $columns['address1']);
    }

    /**
     * Assert the default connection holds only the users table the basic migration creates.
     *
     * @param array<string, SchemaConnection> $connections
     */
    private function assertUsersTableSchema(array $connections): void
    {
        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertSame([
            'id' => ['int', false],
            'name' => ['string', true],
            'email' => ['string', false],
            'created_at' => ['string', true],
            'updated_at' => ['string', true],
        ], $this->describeColumns($connections, 'users'));
    }

    #[Test]
    public function itCanHandleMigrationsWithArrayPassedToDropColumn(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/migrations_using_drop_column',
        ], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanHandleMigrationsWithDropTimestamps(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/migrations_using_drop_timestamps',
        ], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertSame([
            'id' => ['int', false],
            'name' => ['string', true],
            'email' => ['string', false],
        ], $this->describeColumns($connections, 'users'));
    }

    #[Test]
    public function itCanHandleMigrationsWithDropRememberToken(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [
            __DIR__ . '/data/migrations_using_drop_remember_token',
        ], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertSame([
            'id' => ['int', false],
            'name' => ['string', true],
            'email' => ['string', false],
        ], $this->describeColumns($connections, 'users'));
    }

    #[Test]
    public function itCanHandleMigrationsWithIfStatements(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/conditional_migrations'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        self::assertSame([
            'id' => ['int', false],
            'name' => ['string', true],
            'address1' => ['string', false],
            'address2' => ['string', true],
            'email' => ['string', false],
        ], $this->describeColumns($connections, 'users'));
    }

    #[Test]
    public function itCanHandleMigrationsWithConstAsTable(): void
    {
        $migrationHelper = new MigrationHelper($this->parser, [__DIR__ . '/data/migration_with_const'], $this->fileHelper, false, $this->reflectionProvider, $this->initializerExprTypeResolver, $this->connectionConfigResolver);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    /**
     * Unlike upstream, the column is named after the model's custom key, as Blueprint::foreignIdFor() names it.
     */
    #[Test]
    public function itResolvesForeignIdForColumnTypeUsingTablePhpAttribute(): void
    {
        $migrationHelper = new MigrationHelper(
            $this->parser,
            [
                __DIR__ . '/data/basic_migration',
                __DIR__ . '/data/migration_with_foreign_id_for',
            ],
            $this->fileHelper,
            false,
            $this->reflectionProvider,
            $this->initializerExprTypeResolver,
            $this->connectionConfigResolver,
        );

        $connections = $migrationHelper->initializeConnections();

        self::assertSame(['string', false], $this->describeColumns($connections, 'articles')['member_with_custom_key_uuid']);
    }
}
