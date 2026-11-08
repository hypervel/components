<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\PHPStan\Properties\ConnectionConfig;
use Hypervel\PHPStan\Properties\ConnectionConfigResolver;
use Hypervel\Tests\PHPStan\Fixtures\OverridesConfig;
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
            $resolver = new ConnectionConfigResolver;
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
            $resolver = new ConnectionConfigResolver;

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
            $resolver = new ConnectionConfigResolver;

            $this->assertSame('direct', $this->connection($resolver, 'pooler')->owner);
            $this->assertSame('direct', $this->connection($resolver, 'direct')->owner);
        });
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
