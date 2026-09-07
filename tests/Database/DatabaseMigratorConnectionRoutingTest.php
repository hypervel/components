<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\ConnectionResolverInterface as Resolver;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

class DatabaseMigratorConnectionRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        Migrator::flushState();

        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testFlushStateRestoresStaticState(): void
    {
        $reflection = new ReflectionClass(Migrator::class);
        $reflection->setStaticPropertyValue('connectionResolverCallback', static fn () => null);
        $reflection->setStaticPropertyValue('requiredPathCache', ['migration.php' => null]);
        $reflection->setStaticPropertyValue('withoutMigrations', ['2024_01_01_000000_create_users_table']);

        Migrator::flushState();

        $this->assertNull($reflection->getStaticPropertyValue('connectionResolverCallback'));
        $this->assertSame([], $reflection->getStaticPropertyValue('requiredPathCache'));
        $this->assertSame([], $reflection->getStaticPropertyValue('withoutMigrations'));
    }

    public function testResolveMigrationConnectionNameRejectsMissingEffectiveDefault(): void
    {
        $this->bindConfig([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration connection name cannot be empty.');

        Migrator::resolveMigrationConnectionName(null);
    }

    public function testResolveMigrationConnectionNameReturnsOriginalWhenNoMigrationsConnectionConfigured(): void
    {
        $this->bindConfig([
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql'));
    }

    public function testResolveMigrationConnectionNameReturnsMigrationsConnectionWhenConfigured(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql-pooled'));
        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql'));
    }

    public function testResolveMigrationConnectionNameAllowsATerminalSelfReference(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
        ]);

        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql-pooled'));
        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql'));
    }

    public function testResolveMigrationConnectionNameIsDriverAgnostic(): void
    {
        $this->bindConfig([
            'mysql-pooled' => ['driver' => 'mysql', 'migrations_connection' => 'mysql'],
            'mysql' => ['driver' => 'mysql'],
        ]);

        $this->assertSame('mysql', Migrator::resolveMigrationConnectionName('mysql-pooled'));
    }

    public function testResolveMigrationConnectionNameReturnsOriginalWhenConfigBindingMissing(): void
    {
        // No container/config set up — helper should pass the name through
        // rather than throw. Protects unit tests that construct Migrator
        // without a fully booted framework.
        Container::setInstance(null);

        $this->assertSame('pgsql-pooled', Migrator::resolveMigrationConnectionName('pgsql-pooled'));
    }

    public function testResolveMigrationConnectionNameReturnsOriginalWhenTargetConnectionUnknown(): void
    {
        // If the named connection doesn't exist in config, we pass through;
        // the resolver (not our helper) surfaces the "not configured" error.
        $this->bindConfig([]);

        $this->assertSame('ghost', Migrator::resolveMigrationConnectionName('ghost'));
    }

    public function testResolveMigrationConnectionNameNullPrefersContextOverConfigDefault(): void
    {
        // Regression for the "effective default" fix. Scenario:
        //   DB::usingConnection('tenant-pooled', fn () => Artisan::call('migrate'))
        // Context holds 'tenant-pooled' via the outer scope. config.default is
        // something unrelated ('pgsql'). The Migrator helper must route via
        // the Context value — otherwise programmatic migrations inside a
        // scoped override silently hit the configured default instead.
        $this->bindConfig(
            connections: [
                'pgsql' => ['driver' => 'pgsql'],
                'tenant-pooled' => [
                    'driver' => 'pgsql',
                    'migrations_connection' => 'tenant-direct',
                ],
                'tenant-direct' => ['driver' => 'pgsql'],
            ],
            default: 'pgsql',
        );

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'tenant-pooled');

        $this->assertSame(
            'tenant-direct',
            Migrator::resolveMigrationConnectionName(null),
            'Null input must resolve via the Context override, not the configured default',
        );
    }

    public function testResolveMigrationConnectionNameNullReturnsContextValueWhenNoMigrationsConnection(): void
    {
        // Edge case: Context override is set, but that connection has no
        // migrations_connection key. Helper returns the Context value unchanged
        // — no unexpected swap.
        $this->bindConfig(
            connections: [
                'plain-conn' => ['driver' => 'pgsql'],
            ],
            default: null,
        );

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'plain-conn');

        $this->assertSame(
            'plain-conn',
            Migrator::resolveMigrationConnectionName(null),
        );
    }

    public function testResolveMigrationConnectionNameNullFallsBackToConfigWhenNoContext(): void
    {
        // Regression guard: the config-default fallback path still works when
        // no Context override is present. This is the CLI migration path
        // (fresh coroutine, no outer scope).
        $this->bindConfig(
            connections: [
                'tenant-pooled' => [
                    'driver' => 'pgsql',
                    'migrations_connection' => 'tenant-direct',
                ],
                'tenant-direct' => ['driver' => 'pgsql'],
            ],
            default: 'tenant-pooled',
        );

        // Context is not set.
        $this->assertSame(
            'tenant-direct',
            Migrator::resolveMigrationConnectionName(null),
        );
    }

    public function testResolveMigrationConnectionNameRejectsAnEmptySource(): void
    {
        $this->bindConfig([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration connection name cannot be empty.');

        Migrator::resolveMigrationConnectionName('');
    }

    public function testResolveMigrationConnectionNameRejectsAnEmptyTarget(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => ''],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The migrations_connection value for database connection [pgsql-pooled] cannot be empty.'
        );

        Migrator::resolveMigrationConnectionName('pgsql-pooled');
    }

    public function testResolveMigrationConnectionNameRejectsANonStringTarget(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => ['pgsql']],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configuration value for key [database.connections.pgsql-pooled.migrations_connection] must be a string, array given.'
        );

        Migrator::resolveMigrationConnectionName('pgsql-pooled');
    }

    #[DataProvider('nonTerminalMigrationConnectionRoutes')]
    public function testResolveMigrationConnectionNameRejectsANonTerminalRoute(
        array $connections,
        string $message,
    ): void {
        $this->bindConfig($connections);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Migrator::resolveMigrationConnectionName('first');
    }

    /**
     * Get non-terminal migration connection routes.
     */
    public static function nonTerminalMigrationConnectionRoutes(): array
    {
        return [
            'chain' => [
                [
                    'first' => ['driver' => 'pgsql', 'migrations_connection' => 'second'],
                    'second' => ['driver' => 'pgsql', 'migrations_connection' => 'third'],
                    'third' => ['driver' => 'pgsql'],
                ],
                'Database connection [first] routes migrations to [second], but [second] routes migrations to [third]. Migration connections must resolve directly to a terminal connection.',
            ],
            'cycle' => [
                [
                    'first' => ['driver' => 'pgsql', 'migrations_connection' => 'second'],
                    'second' => ['driver' => 'pgsql', 'migrations_connection' => 'first'],
                ],
                'Database connection [first] routes migrations to [second], but [second] routes migrations to [first]. Migration connections must resolve directly to a terminal connection.',
            ],
        ];
    }

    public function testSetConnectionWritesContextRepositorySourceAndStoredName(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);

        $repository->shouldReceive('setSource')->once()->with('pgsql');

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection('pgsql-pooled');

        $this->assertSame('pgsql', $migrator->getConnection());
        $this->assertSame(
            'pgsql',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'setConnection should write the swapped name to coroutine Context',
        );
    }

    public function testSetConnectionRejectsNullWithoutAnEffectiveDefault(): void
    {
        $this->bindConfig([]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldNotReceive('setSource');

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration connection name cannot be empty.');

        $migrator->setConnection(null);
    }

    public function testSetConnectionDoesNotSwapWhenNoMigrationsConnectionKey(): void
    {
        $this->bindConfig([
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);

        $repository->shouldReceive('setSource')->once()->with('pgsql');

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection('pgsql');

        $this->assertSame('pgsql', $migrator->getConnection());
        $this->assertSame(
            'pgsql',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testResolveConnectionUsesStoredConnectionWhenArgumentIsNullOrEmpty(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $repository->shouldReceive('setSource')->once()->with('pgsql');
        $resolver->shouldReceive('connection')->twice()->with('pgsql')->andReturn($resolvedConnection);

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection('pgsql-pooled');

        $this->assertSame($resolvedConnection, $migrator->resolveConnection(null));
        $this->assertSame($resolvedConnection, $migrator->resolveConnection(''));
    }

    public function testResolveConnectionPreservesExplicitZeroConnection(): void
    {
        $this->bindConfig(
            connections: [
                '0' => ['driver' => 'pgsql'],
                'pgsql' => ['driver' => 'pgsql'],
            ],
            default: 'pgsql',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $resolver->shouldReceive('connection')->once()->with('0')->andReturn($resolvedConnection);

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $this->assertSame($resolvedConnection, $migrator->resolveConnection('0'));
    }

    public function testResolveConnectionSwapsPerMigrationConnectionOverride(): void
    {
        // A migration file can return a specific connection name from its
        // getConnection(). If that connection has migrations_connection set,
        // the override should still route through the sibling.
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $resolver->shouldReceive('connection')->once()->with('pgsql')->andReturn($resolvedConnection);

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $this->assertSame($resolvedConnection, $migrator->resolveConnection('pgsql-pooled'));
    }

    public function testResolveConnectionPassesSwappedNameToCustomCallback(): void
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $capturedName = null;
        Migrator::resolveConnectionsUsing(function (Resolver $r, ?string $name) use (&$capturedName, $resolvedConnection) {
            $capturedName = $name;
            return $resolvedConnection;
        });

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $result = $migrator->resolveConnection('pgsql-pooled');

        $this->assertSame('pgsql', $capturedName, 'Custom callback should receive the swapped connection name');
        $this->assertSame($resolvedConnection, $result);
    }

    public function testResolveConnectionPassesOriginalNameWhenNoMigrationsConnection(): void
    {
        $this->bindConfig([
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $resolver->shouldReceive('connection')->once()->with('pgsql')->andReturn($resolvedConnection);

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $this->assertSame($resolvedConnection, $migrator->resolveConnection('pgsql'));
    }

    public function testSetConnectionWithNullAndPooledDefaultRoutesToDirect(): void
    {
        // Regression: null name must resolve via database.default. When the
        // app default is pooled, setConnection(null) should end up at the
        // direct sibling, not leave migrations running against the pooler.
        $this->bindConfig(
            connections: [
                'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                'pgsql' => ['driver' => 'pgsql'],
            ],
            default: 'pgsql-pooled',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->once()->with('pgsql');

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection(null);

        $this->assertSame('pgsql', $migrator->getConnection());
        $this->assertSame(
            'pgsql',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testResolveConnectionWithNullAndPooledDefaultRoutesToDirect(): void
    {
        // Regression: fresh Migrator (no setConnection called) handling a
        // per-migration override of null (or missing getConnection()). The
        // helper's null fallback via database.default should still route
        // to the direct sibling.
        $this->bindConfig(
            connections: [
                'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                'pgsql' => ['driver' => 'pgsql'],
            ],
            default: 'pgsql-pooled',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $resolver->shouldReceive('connection')->once()->with('pgsql')->andReturn($resolvedConnection);

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $this->assertSame($resolvedConnection, $migrator->resolveConnection(null));
    }

    public function testUsingConnectionRestoresDistinctStoredAndContextState(): void
    {
        $this->bindConfig(
            connections: [
                'stored' => ['driver' => 'pgsql'],
                'context-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'context-direct'],
                'context-direct' => ['driver' => 'pgsql'],
                'inner-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'inner-direct'],
                'inner-direct' => ['driver' => 'pgsql'],
            ],
            default: 'stored',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->twice()->with('stored');
        $repository->shouldReceive('setSource')->once()->with('inner-direct');

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection('stored');
        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'context-pooled');

        $innerStored = null;
        $innerContext = null;
        $migrator->usingConnection('inner-pooled', function () use ($migrator, &$innerStored, &$innerContext): void {
            $innerStored = $migrator->getConnection();
            $innerContext = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        });

        $this->assertSame('inner-direct', $innerStored);
        $this->assertSame('inner-direct', $innerContext);
        $this->assertSame('stored', $migrator->getConnection());
        $this->assertSame(
            'context-pooled',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testUsingConnectionRestoresDistinctStoredAndContextStateAfterAnException(): void
    {
        $this->bindConfig(
            connections: [
                'stored' => ['driver' => 'pgsql'],
                'context-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'context-direct'],
                'context-direct' => ['driver' => 'pgsql'],
                'inner-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'inner-direct'],
                'inner-direct' => ['driver' => 'pgsql'],
            ],
            default: 'stored',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->twice()->with('stored');
        $repository->shouldReceive('setSource')->once()->with('inner-direct');

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection('stored');
        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'context-pooled');
        $failure = new RuntimeException('migration callback failed');

        try {
            $migrator->usingConnection('inner-pooled', static fn (): never => throw $failure);
            $this->fail('Expected the migration callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame('stored', $migrator->getConnection());
        $this->assertSame(
            'context-pooled',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testUsingConnectionWithNullAndPooledDefaultRoutesAndRestores(): void
    {
        // Combined regression for issues 1 and 2: null input must route via
        // database.default, AND the restoration must bring back the exact
        // prior Context value (here: null/cleared).
        $this->bindConfig(
            connections: [
                'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                'pgsql' => ['driver' => 'pgsql'],
            ],
            default: 'pgsql-pooled',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);

        $repository->shouldReceive('setSource')->once()->with('pgsql');
        $repository->shouldReceive('setSource')->once()->with(null);

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $innerStored = null;
        $innerContext = null;
        $migrator->usingConnection(null, function () use (&$innerStored, &$innerContext, $migrator) {
            $innerStored = $migrator->getConnection();
            $innerContext = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        });

        $this->assertSame('pgsql', $innerStored);
        $this->assertSame('pgsql', $innerContext);
        $this->assertNull(
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'Context must be cleared after usingConnection if there was no prior override',
        );
        $this->assertNull($migrator->getConnection());
    }

    public function testGetMigrationConnectionsDiscoversNamedAnonymousAndContextDeclaredTargets(): void
    {
        $this->bindConfig(
            connections: [
                'default-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'default-direct'],
                'default-direct' => ['driver' => 'pgsql'],
                'analytics-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'analytics-direct'],
                'reporting-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'analytics-direct'],
                'analytics-direct' => ['driver' => 'pgsql'],
                'context-target' => ['driver' => 'pgsql'],
                'wrong-target' => ['driver' => 'pgsql'],
            ],
            default: 'default-pooled',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->once()->with('default-direct');
        $repository->shouldReceive('setSource')->once()->with(null);
        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $connections = $migrator->getMigrationConnections([
            __DIR__ . '/migrations/one',
            __DIR__ . '/migrations/connection_targets',
        ]);

        $this->assertSame(
            ['default-direct', 'analytics-direct', 'context-target'],
            $connections,
        );
        $this->assertNull($migrator->getConnection());
        $this->assertNull(CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY));
    }

    public function testGetMigrationConnectionsIncludesTheDefaultWithoutMigrationFiles(): void
    {
        $this->bindConfig(
            connections: [
                'default-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'default-direct'],
                'default-direct' => ['driver' => 'pgsql'],
            ],
            default: 'default-pooled',
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->once()->with('default-direct');
        $repository->shouldReceive('setSource')->once()->with(null);
        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $this->assertSame(
            ['default-direct'],
            $migrator->getMigrationConnections(__DIR__ . '/migrations/missing'),
        );
    }

    public function testNestedUsingConnectionPreservesEachLevelsState(): void
    {
        // Regression guard: each frame must snapshot and restore its own
        // prior state. Outer sets 'pgsql-pooled' → 'pgsql'. Inner sets
        // 'mysql-pooled' → 'mysql'. When inner unwinds, Context/stored
        // should return to outer's values ('pgsql'), not be forgotten or
        // left at 'mysql'.
        $this->bindConfig(
            connections: [
                'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                'pgsql' => ['driver' => 'pgsql'],
                'mysql-pooled' => ['driver' => 'mysql', 'migrations_connection' => 'mysql'],
                'mysql' => ['driver' => 'mysql'],
            ],
            default: null,
        );

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);

        // Outer enter: setSource('pgsql'). Inner enter: setSource('mysql').
        // Inner exit: setSource back to 'pgsql' (outer's stored). Outer exit:
        // setSource back to null.
        $repository->shouldReceive('setSource')->with('pgsql')->twice();
        $repository->shouldReceive('setSource')->with('mysql')->once();
        $repository->shouldReceive('setSource')->with(null)->once();

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $observations = [];

        $migrator->usingConnection('pgsql-pooled', function () use ($migrator, &$observations) {
            $observations['outer-entered'] = [
                'stored' => $migrator->getConnection(),
                'context' => CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            ];

            $migrator->usingConnection('mysql-pooled', function () use ($migrator, &$observations) {
                $observations['inner-entered'] = [
                    'stored' => $migrator->getConnection(),
                    'context' => CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
                ];
            });

            $observations['after-inner'] = [
                'stored' => $migrator->getConnection(),
                'context' => CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            ];
        });

        $observations['after-outer'] = [
            'stored' => $migrator->getConnection(),
            'context' => CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        ];

        $this->assertSame(['stored' => 'pgsql', 'context' => 'pgsql'], $observations['outer-entered']);
        $this->assertSame(['stored' => 'mysql', 'context' => 'mysql'], $observations['inner-entered']);
        $this->assertSame(['stored' => 'pgsql', 'context' => 'pgsql'], $observations['after-inner']);
        $this->assertSame(['stored' => null, 'context' => null], $observations['after-outer']);
    }

    protected function bindConfig(array $connections, ?string $default = null): void
    {
        $container = Container::getInstance();
        $container->instance('config', new Repository([
            'database' => [
                'default' => $default,
                'connections' => $connections,
            ],
        ]));
    }
}
