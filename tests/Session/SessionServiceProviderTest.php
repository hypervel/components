<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Session\SessionServiceProvider;
use Hypervel\Testbench\TestCase;
use ReflectionClass;

class SessionServiceProviderTest extends TestCase
{
    public function testReloadConfigurationPreservesRedirectorGraphAndReplacesSessionStore(): void
    {
        $redirector = $this->app->make('redirect');
        $responseFactory = $this->app->make(ResponseFactoryContract::class);
        $session = $this->app->make('session');
        $store = $this->app->make('session.store');

        $this->app->getProvider(SessionServiceProvider::class)->reloadConfiguration();

        $refreshedStore = $this->app->make('session.store');

        $this->assertSame($session, $this->app->make('session'));
        $this->assertNotSame($store, $refreshedStore);
        $this->assertSame($redirector, $this->app->make('redirect'));
        $this->assertSame($responseFactory, $this->app->make(ResponseFactoryContract::class));
        $this->assertSame(
            $refreshedStore,
            (new ReflectionClass($redirector))->getProperty('session')->getValue($redirector),
        );
        $this->assertSame(
            $redirector,
            (new ReflectionClass($responseFactory))->getProperty('redirector')->getValue($responseFactory),
        );
    }
}
