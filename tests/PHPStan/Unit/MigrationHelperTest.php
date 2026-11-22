<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\PHPStan\Properties\SchemaConnection;
use Hypervel\PHPStan\Schema\BlueprintMacroExtension;
use Hypervel\Tests\PHPStan\Fixtures\Bootstrap\Billing\BillingBlueprintMacros;
use Hypervel\Tests\PHPStan\Fixtures\Bootstrap\Billing\BillingSchema;
use Hypervel\Tests\PHPStan\Fixtures\CreatesMigrationHelpers;
use Hypervel\Tests\PHPStan\Fixtures\ReadsSchemaFacts;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\BillingConfiguration;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\ReboundBillingConfiguration;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\SentinelMigration;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use LogicException;
use PHPStan\DependencyInjection\Container;
use PHPStan\File\FileHelper;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;

/**
 * Unlike upstream, the facts belong to the connection each migration runs on, the routed default
 * connection here (SQLite), and columns are described by the types that connection fetches.
 */
class MigrationHelperTest extends PHPStanTestCase
{
    use CreatesMigrationHelpers;
    use ReadsSchemaFacts;
    use VerifiesDoubles;

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon', __DIR__ . '/../Fixtures/Bootstrap/Billing/extension.neon'];
    }

    #[Test]
    public function itMatchesLiveBlueprintMacrosAndRejectsConflictingDescriptions(): void
    {
        $property = new ReflectionProperty(Blueprint::class, 'macros');
        $original = $property->getValue();

        try {
            BillingSchema::register();
            $helper = $this->createMigrationHelper();
            $this->assertSame(BillingSchema::class, $helper->getBlueprintMacros()['invoiceReference']['owner']);
            Blueprint::macro('invoiceReference', static function (): void {});
            $this->assertArrayNotHasKey('invoiceReference', $this->createMigrationHelper()->getBlueprintMacros());

            $container = Double::for(Container::class)->strict();
            $container->expects('getServicesByTag')->with(BlueprintMacroExtension::TAG)->returns([new BillingBlueprintMacros, new DuplicateBillingMacros]);

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage(sprintf('Blueprint macro [billingCustomer] registered by [%s] is described by both [%s] and [%s].', BillingSchema::class, BillingBlueprintMacros::class, DuplicateBillingMacros::class));
            $this->createMigrationHelper(extensions: $container)->getBlueprintMacros();
        } finally {
            $property->setValue(null, $original);
        }
    }

    #[Test]
    public function itTracksTheImplementationOfAMappedMigrationService(): void
    {
        $resolver = new ConnectionConfigResolver([SentinelMigration::class => BillingConfiguration::class . '@connection']);
        $container = clone $resolver->getContainer();
        $container->instance(BillingConfiguration::class, new ReboundBillingConfiguration('mysql'));
        $resolver->setContainer($container);
        $helper = $this->createMigrationHelper([__DIR__ . '/../Fixtures/SchemaAggregator/Migrations'], resolver: $resolver);

        $aggregated = $helper->aggregate();

        $this->assertSame(['name' => ['string', false]], $this->describeColumns($aggregated->connections, 'mapped', 'mysql'));
        $this->assertArrayHasKey((new ReflectionClass(ReboundBillingConfiguration::class))->getFileName(), $aggregated->sources);
        $this->assertSame(0, SentinelMigration::$executions);
    }

    #[Test]
    public function itKeepsAMigrationDirectoryThatDoesNotExist(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/missing']);

        self::assertSame([self::getContainer()->getByType(FileHelper::class)->absolutizePath(__DIR__ . '/data/missing') => '*.[pP][hH][pP]'], $migrationHelper->getMigrationPaths());
        self::assertSame([], $migrationHelper->getMigrationFiles());
    }

    #[Test]
    public function itHasNoMigrationDirectoriesWhenTheMigrationScanIsDisabled(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/basic_migration'], disableScan: true);

        self::assertSame([], $migrationHelper->getMigrationPaths());
    }

    #[Test]
    public function itWillReturnEmptyArrayIfMigrationsPathIsNotADirectory(): void
    {
        $migrationHelper = $this->createMigrationHelper(['foobar']);

        self::assertSame([], $migrationHelper->initializeConnections());
    }

    #[Test]
    public function itCanReadBasicMigrationsAndCreateTableStructure(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/basic_migration']);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanReadSchemaDefinitionsFromAnyMethodInClass(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migrations_with_different_methods']);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanReadSchemaDefinitionsWithMultipleCreateAndDropMethodsForOneTable(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/complex_migrations']);

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
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/basic_migration',
            __DIR__ . '/data/additional_migrations',
        ]);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(2, $this->findSchema($connections)->tables);
        self::assertNotNull($this->findTable($connections, 'users'));
        self::assertNotNull($this->findTable($connections, 'teams'));
    }

    #[Test]
    public function itCanHandleUseOfAfterMethodInMigration(): void
    {
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/migrations_using_after_method',
        ]);

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
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/rename_migrations',
        ]);

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
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migrations_using_soft_deletes']);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertCount(6, $this->describeColumns($connections, 'users'));
        self::assertSame(['string', true], $this->describeColumns($connections, 'users')['deleted_at']);
    }

    #[Test]
    public function itCanHandleMigrationsWithSoftDeletesTz(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migrations_using_soft_deletes_tz']);

        $connections = $migrationHelper->initializeConnections();

        self::assertCount(1, $this->findSchema($connections)->tables);
        self::assertCount(6, $this->describeColumns($connections, 'users'));
        self::assertSame(['string', true], $this->describeColumns($connections, 'users')['deleted_at']);
    }

    #[Test]
    public function itCanHandleMigrationsWithNullableTimestampsTz(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migrations_using_nullable_timestamps_tz']);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanHandleMigrationsWithDefaultArguments(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migration_with_default_arguments']);

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
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migration_with_schema_connection']);

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
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/basic_migration',
            __DIR__ . '/data/additional_migrations',
        ], disableScan: true);

        $connections = $migrationHelper->initializeConnections();

        self::assertSame([], $connections);
    }

    #[Test]
    public function itCanHandleNullableInMigrations(): void
    {
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migrations_using_nullable']);

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
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/migrations_using_drop_column',
        ]);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    #[Test]
    public function itCanHandleMigrationsWithDropTimestamps(): void
    {
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/migrations_using_drop_timestamps',
        ]);

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
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/migrations_using_drop_remember_token',
        ]);

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
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/conditional_migrations']);

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
        $migrationHelper = $this->createMigrationHelper([__DIR__ . '/data/migration_with_const']);

        $connections = $migrationHelper->initializeConnections();

        $this->assertUsersTableSchema($connections);
    }

    /**
     * Unlike upstream, the column is named after the model's custom key, as Blueprint::foreignIdFor() names it.
     */
    #[Test]
    public function itResolvesForeignIdForColumnTypeUsingTablePhpAttribute(): void
    {
        $migrationHelper = $this->createMigrationHelper([
            __DIR__ . '/data/basic_migration',
            __DIR__ . '/data/migration_with_foreign_id_for',
        ]);

        $connections = $migrationHelper->initializeConnections();

        self::assertSame(['string', false], $this->describeColumns($connections, 'articles')['member_with_custom_key_uuid']);
    }
}

class DuplicateBillingMacros extends BillingBlueprintMacros
{
}
