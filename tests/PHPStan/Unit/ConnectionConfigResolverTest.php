<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Container\Container;
use Hypervel\PHPStan\Properties\ConnectionConfig;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\Tests\PHPStan\Fixtures\OverridesConfig;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\BillingConfiguration;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\BillingMigration;
use Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator\SentinelMigration;
use InvalidArgumentException;
use PDO;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

class ConnectionConfigResolverTest extends PHPStanTestCase
{
    use OverridesConfig;

    /**
     * Each role's records can set their own fetch options, and a name without a role reaches both.
     */
    #[Test]
    public function itReadsTheFetchOptionsOfEveryRecordANameSelects(): void
    {
        $this->withConfig(['database.connections.split' => [
            'driver' => 'mysql',
            'database' => 'app',
            'read' => ['host' => 'replica', 'options' => [PDO::ATTR_STRINGIFY_FETCHES => true]],
            'write' => ['host' => 'primary'],
        ]], function (): void {
            $resolver = new ConnectionConfigResolver([]);
            $stringified = fn (string $name): array => array_map(
                static fn (array $options): mixed => $options[PDO::ATTR_STRINGIFY_FETCHES],
                $this->connection($resolver, $name)->fetchOptions,
            );

            $this->assertSame([true, false], $stringified('split'));
            $this->assertSame([true], $stringified('split::read'));
            $this->assertSame([false], $stringified('split::write'));
        });
    }

    /**
     * A pool's records can reach different hosts, but a lookup cannot tell which of several databases a query reaches.
     */
    #[Test]
    public function itResolvesFactsOnlyWhenEveryRecordReachesTheSameDatabase(): void
    {
        $this->withConfig([
            'database.connections.pooled' => ['driver' => 'mysql', 'database' => 'app', 'read' => ['host' => 'replica'], 'write' => ['host' => 'primary']],
            'database.connections.diverging' => ['driver' => 'mysql', 'database' => 'app', 'read' => ['database' => 'reporting'], 'write' => ['host' => 'primary']],
        ], function (): void {
            $resolver = new ConnectionConfigResolver([]);

            $this->assertSame('app', $this->connection($resolver, 'pooled')->defaultSchema);
            $this->assertNull($resolver->connection('diverging'));
            $this->assertSame('reporting', $this->connection($resolver, 'diverging::read')->defaultSchema);
        });
    }

    /**
     * The schema facts belong to the connection the migrations run on, such as a direct connection behind a pooler.
     */
    #[Test]
    public function itResolvesTheConnectionMigrationsRunOn(): void
    {
        $this->withConfig([
            'database.connections.pooler' => ['driver' => 'pgsql', 'database' => 'app', 'migrations_connection' => 'direct'],
            'database.connections.direct' => ['driver' => 'pgsql', 'database' => 'app'],
        ], function (): void {
            $resolver = new ConnectionConfigResolver([]);

            $this->assertSame('direct', $this->connection($resolver, 'pooler')->owner);
            $this->assertSame('direct', $this->connection($resolver, 'direct')->owner);
        });
    }

    #[Test]
    public function itRecordsTheBooleanInputsOfMigrationGuards(): void
    {
        $this->withConfig(['billing.enabled' => false, 'billing.invalid' => 'true'], function (): void {
            $resolver = new ConnectionConfigResolver([]);

            foreach (['billing.enabled', 'billing.invalid', 'billing.missing'] as $key) {
                $resolver->getConfiguredBoolean($key);
            }

            $this->assertSame([
                'billing.enabled' => [true, false],
                'billing.invalid' => [true, null],
                'billing.missing' => [false, null],
            ], $resolver->getConfiguredBooleans());
            $this->assertSame(array_keys($resolver->getConfiguredBooleans()), $resolver->getConsumedKeys());
        });
    }

    #[Test]
    public function itResolvesOnlyUsedMigrationCallbacksAndKeepsTheirResults(): void
    {
        $resolver = new ConnectionConfigResolver([
            BillingMigration::class => 'billing.config@connection',
            SentinelMigration::class => BillingConfiguration::class . '@connection',
        ]);
        $container = new Container;
        $service = new BillingConfiguration(null);
        $container->instance('billing.config', $service);
        $resolver->setContainer($container);
        $instances = BillingConfiguration::$instances;

        $this->assertNull($resolver->resolveMigrationConnection('unmapped'));
        $this->assertSame([true, null], $resolver->resolveMigrationConnection(BillingMigration::class));
        $service->name = 5;
        $this->assertSame([true, null], $resolver->resolveMigrationConnection(BillingMigration::class));
        $this->assertSame(1, $service->calls);
        $this->assertSame($instances, BillingConfiguration::$instances);

        $container->instance(BillingConfiguration::class, $service);
        $this->assertSame([false, null], $resolver->resolveMigrationConnection(SentinelMigration::class));
    }

    #[Test]
    public function itRequiresAMethodOnAMappedConnectionCallback(): void
    {
        $resolver = new ConnectionConfigResolver([BillingMigration::class => BillingConfiguration::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(BillingMigration::class);
        $resolver->resolveMigrationConnection(BillingMigration::class);
    }

    /**
     * Get the facts of a connection name, failing when they cannot be resolved.
     */
    private function connection(ConnectionConfigResolver $resolver, string $name): ConnectionConfig
    {
        $connection = $resolver->connection($name);

        $this->assertNotNull($connection, "The connection [{$name}] cannot be resolved.");

        return $connection;
    }
}
