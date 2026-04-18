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
use Mockery as m;
use ReflectionClass;

class DatabaseMigratorConnectionRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the static callback to prevent cross-test leakage.
        $reflection = new ReflectionClass(Migrator::class);
        $property = $reflection->getProperty('connectionResolverCallback');
        $property->setValue(null, null);

        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testResolveMigrationConnectionNameReturnsNullForNullInput()
    {
        $this->bindConfig([]);

        $this->assertNull(Migrator::resolveMigrationConnectionName(null));
    }

    public function testResolveMigrationConnectionNameReturnsOriginalWhenNoMigrationsConnectionConfigured()
    {
        $this->bindConfig([
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql'));
    }

    public function testResolveMigrationConnectionNameReturnsMigrationsConnectionWhenConfigured()
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $this->assertSame('pgsql', Migrator::resolveMigrationConnectionName('pgsql-pooled'));
    }

    public function testResolveMigrationConnectionNameIsDriverAgnostic()
    {
        $this->bindConfig([
            'mysql-pooled' => ['driver' => 'mysql', 'migrations_connection' => 'mysql'],
            'mysql' => ['driver' => 'mysql'],
        ]);

        $this->assertSame('mysql', Migrator::resolveMigrationConnectionName('mysql-pooled'));
    }

    public function testResolveMigrationConnectionNameReturnsOriginalWhenConfigBindingMissing()
    {
        // No container/config set up — helper should pass the name through
        // rather than throw. Protects unit tests that construct Migrator
        // without a fully booted framework.
        Container::setInstance(null);

        $this->assertSame('pgsql-pooled', Migrator::resolveMigrationConnectionName('pgsql-pooled'));
    }

    public function testResolveMigrationConnectionNameReturnsOriginalWhenTargetConnectionUnknown()
    {
        // If the named connection doesn't exist in config, we pass through;
        // the resolver (not our helper) surfaces the "not configured" error.
        $this->bindConfig([]);

        $this->assertSame('ghost', Migrator::resolveMigrationConnectionName('ghost'));
    }

    public function testResolveMigrationConnectionNameNullPrefersContextOverConfigDefault()
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

    public function testResolveMigrationConnectionNameNullReturnsContextValueWhenNoMigrationsConnection()
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

    public function testResolveMigrationConnectionNameNullFallsBackToConfigWhenNoContext()
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

    public function testSetConnectionWritesContextRepositorySourceAndStoredName()
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

    public function testSetConnectionWithNullAndNoEffectiveDefaultStoresNull()
    {
        // No Context override AND no database.default — there's nothing to
        // fall back to, so setConnection(null) stores null and leaves Context
        // untouched (already absent).
        $this->bindConfig([]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $repository->shouldReceive('setSource')->once()->with(null);

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection(null);

        $this->assertNull($migrator->getConnection());
        $this->assertNull(
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testSetConnectionDoesNotSwapWhenNoMigrationsConnectionKey()
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

    public function testResolveConnectionUsesStoredConnectionWhenArgumentIsNull()
    {
        $this->bindConfig([
            'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
            'pgsql' => ['driver' => 'pgsql'],
        ]);

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $resolvedConnection = m::mock(Connection::class);

        $repository->shouldReceive('setSource')->once()->with('pgsql');
        $resolver->shouldReceive('connection')->once()->with('pgsql')->andReturn($resolvedConnection);

        $migrator = new Migrator($repository, $resolver, new Filesystem);
        $migrator->setConnection('pgsql-pooled');

        $this->assertSame($resolvedConnection, $migrator->resolveConnection(null));
    }

    public function testResolveConnectionSwapsPerMigrationConnectionOverride()
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

    public function testResolveConnectionPassesSwappedNameToCustomCallback()
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

    public function testResolveConnectionPassesOriginalNameWhenNoMigrationsConnection()
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

    public function testSetConnectionWithNullAndPooledDefaultRoutesToDirect()
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

    public function testResolveConnectionWithNullAndPooledDefaultRoutesToDirect()
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

    public function testUsingConnectionRestoresExactPriorState()
    {
        // Regression for issue 2: usingConnection must restore the user-facing
        // alias, not the swapped direct name. Simulate pre-existing state: a
        // prior Context override of 'pgsql-pooled' (as if some outer scope
        // had set it) and a stored migrator connection.
        $this->bindConfig(
            connections: [
                'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                'pgsql' => ['driver' => 'pgsql'],
            ],
            default: 'pgsql-pooled',
        );

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'pgsql-pooled');

        $resolver = m::mock(Resolver::class);
        $repository = m::mock(MigrationRepositoryInterface::class);

        // setConnection inside usingConnection sets source to 'pgsql'.
        // Finally restores source to null (the migrator's previous stored state).
        $repository->shouldReceive('setSource')->once()->with('pgsql');
        $repository->shouldReceive('setSource')->once()->with(null);

        $migrator = new Migrator($repository, $resolver, new Filesystem);

        $innerContext = null;
        $migrator->usingConnection('pgsql-pooled', function () use (&$innerContext) {
            $innerContext = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        });

        $this->assertSame('pgsql', $innerContext, 'Inside the callback, Context should be the swapped name');
        $this->assertSame(
            'pgsql-pooled',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'After the callback, Context must be restored to the exact prior alias — not the swapped name',
        );
        $this->assertNull($migrator->getConnection(), 'Stored connection should be restored to the prior null value');
    }

    public function testUsingConnectionWithNullAndPooledDefaultRoutesAndRestores()
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

    public function testNestedUsingConnectionPreservesEachLevelsState()
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
