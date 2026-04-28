<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Regression tests for DatabaseManager::setDefaultConnection() using
 * CoroutineContext instead of mutating config. The companion read path
 * (getDefaultConnection) and coroutine isolation semantics are also
 * verified here since they're meaningless without each other.
 */
class DatabaseManagerSetDefaultConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testSetDefaultConnectionWritesToCoroutineContext()
    {
        $manager = $this->makeManager(['default' => 'pgsql']);

        $manager->setDefaultConnection('reporting');

        $this->assertSame(
            'reporting',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'setDefaultConnection must write to coroutine Context',
        );
    }

    public function testSetDefaultConnectionWithNullClearsContextOverride()
    {
        $manager = $this->makeManager(['default' => 'pgsql']);

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'something');

        $manager->setDefaultConnection(null);

        $this->assertNull(
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'setDefaultConnection(null) must forget the Context override',
        );
    }

    public function testSetDefaultConnectionDoesNotMutateConfig()
    {
        $config = new Repository(['database' => ['default' => 'pgsql']]);
        $manager = $this->makeManager([], $config);

        $manager->setDefaultConnection('reporting');

        $this->assertSame(
            'pgsql',
            $config->get('database.default'),
            'config("database.default") must be untouched — the override lives in Context only',
        );
    }

    public function testGetDefaultConnectionReturnsContextOverrideWhenSet()
    {
        $manager = $this->makeManager(['default' => 'pgsql']);

        $manager->setDefaultConnection('reporting');

        $this->assertSame('reporting', $manager->getDefaultConnection());
    }

    public function testGetDefaultConnectionFallsBackToConfigWhenContextIsCleared()
    {
        $manager = $this->makeManager(['default' => 'pgsql']);

        $manager->setDefaultConnection('reporting');
        $manager->setDefaultConnection(null);

        $this->assertSame('pgsql', $manager->getDefaultConnection());
    }

    public function testOverrideInOneCoroutineIsNotVisibleInSibling()
    {
        $manager = $this->makeManager(['default' => 'pgsql']);

        $observations = [];

        $parent = Coroutine::create(function () use ($manager, &$observations) {
            $manager->setDefaultConnection('reporting');
            $observations['parent'] = $manager->getDefaultConnection();

            // A freshly-spawned sibling coroutine must not see our override
            $sibling = Coroutine::create(function () use ($manager, &$observations) {
                $observations['sibling'] = $manager->getDefaultConnection();
            });
        });

        $this->assertSame('reporting', $observations['parent']);
        $this->assertSame(
            'pgsql',
            $observations['sibling'],
            'Child coroutines must NOT inherit their parent\'s scoped override',
        );
    }

    /**
     * Build a DatabaseManager wired up enough to exercise the setter/getter.
     * The pool/factory machinery isn't needed since no connection is opened.
     */
    protected function makeManager(array $databaseConfig, ?Repository $config = null): DatabaseManager
    {
        $config ??= new Repository(['database' => $databaseConfig]);

        $app = Container::getInstance();
        $app->instance('config', $config);
        $app->instance(PoolFactory::class, m::mock(PoolFactory::class));

        $factory = m::mock(\Hypervel\Database\Connectors\ConnectionFactory::class);

        return new DatabaseManager($app, $factory);
    }
}
