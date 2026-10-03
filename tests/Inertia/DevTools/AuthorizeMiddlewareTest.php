<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Gate;
use Hypervel\Tests\Inertia\TestCase;

class AuthorizeMiddlewareTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('inertia.devtools.enabled', true);
        $config->set('inertia.devtools.gate', 'viewInertiaDevtools');
        $config->set('inertia.devtools.middleware', [StartSession::class]);
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindEntriesRepository();
        $this->app->instance('env', 'production');
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->clearDevToolsStorage();

        parent::tearDown();
    }

    public function testTheConfiguredMiddlewareReplacesTheGateDefault(): void
    {
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => request()->hasSession());

        $this->getJson('/_inertia/devtools/entries')->assertOk();
    }

    public function testAuthorizationStillRunsWhenTheMiddlewareIsConfigured(): void
    {
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => false);

        $this->getJson('/_inertia/devtools/entries')->assertForbidden();
    }
}
