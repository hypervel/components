<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Regression tests for ConnectionResolver::setDefaultConnection() using
 * CoroutineContext. Mirrors the DatabaseManager tests since both
 * implementations share the same Context key and semantics.
 */
class ConnectionResolverSetDefaultConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testSetDefaultConnectionWritesToCoroutineContext()
    {
        $resolver = $this->makeResolver('pgsql');

        $resolver->setDefaultConnection('reporting');

        $this->assertSame(
            'reporting',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testSetDefaultConnectionWithNullClearsContextOverride()
    {
        $resolver = $this->makeResolver('pgsql');

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'reporting');

        $resolver->setDefaultConnection(null);

        $this->assertNull(
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
        );
    }

    public function testGetDefaultConnectionFallsBackToConfigCapturedAtConstruction()
    {
        $resolver = $this->makeResolver('pgsql');

        // No override set — should fall back to the config-captured default
        $this->assertSame('pgsql', $resolver->getDefaultConnection());

        // Override, then clear — should fall back again
        $resolver->setDefaultConnection('reporting');
        $this->assertSame('reporting', $resolver->getDefaultConnection());

        $resolver->setDefaultConnection(null);
        $this->assertSame('pgsql', $resolver->getDefaultConnection());
    }

    public function testOverrideInOneCoroutineIsNotVisibleInSibling()
    {
        $resolver = $this->makeResolver('pgsql');

        $observations = [];

        Coroutine::create(function () use ($resolver, &$observations) {
            $resolver->setDefaultConnection('reporting');
            $observations['parent'] = $resolver->getDefaultConnection();

            Coroutine::create(function () use ($resolver, &$observations) {
                $observations['sibling'] = $resolver->getDefaultConnection();
            });
        });

        $this->assertSame('reporting', $observations['parent']);
        $this->assertSame(
            'pgsql',
            $observations['sibling'],
            'Sibling coroutine must see config-derived default, not the parent\'s override',
        );
    }

    public function testNestedOverrideRestoresExactPriorValue()
    {
        $resolver = $this->makeResolver('pgsql');

        $resolver->setDefaultConnection('outer');
        $this->assertSame('outer', $resolver->getDefaultConnection());

        $previous = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
        try {
            $resolver->setDefaultConnection('inner');
            $this->assertSame('inner', $resolver->getDefaultConnection());
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previous);
            }
        }

        $this->assertSame('outer', $resolver->getDefaultConnection());
    }

    protected function makeResolver(string $configuredDefault): ConnectionResolver
    {
        $app = Container::getInstance();
        $app->instance('config', new Repository([
            'database' => ['default' => $configuredDefault],
        ]));
        $app->instance(PoolFactory::class, m::mock(PoolFactory::class));

        return new ConnectionResolver($app);
    }
}
