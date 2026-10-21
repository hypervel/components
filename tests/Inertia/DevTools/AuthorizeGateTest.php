<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Auth\GenericUser;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\Str;
use Hypervel\Tests\Inertia\TestCase;

class AuthorizeGateTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        // The entry endpoints run the `web` middleware group, which encrypts cookies.
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('inertia.devtools.enabled', true);
        $config->set('inertia.devtools.gate', 'viewInertiaDevtools');
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

    public function testAConfiguredGateIsAuthoritativeOutsideTheLocalEnvironment(): void
    {
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => true);

        $this->getJson('/_inertia/devtools/entries')->assertOk();
        $this->getJson('/_inertia/devtools/entries/' . $this->savedEntryId())->assertOk();
    }

    public function testAFailingGateDeniesAccess(): void
    {
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => false);

        $this->getJson('/_inertia/devtools/entries')->assertForbidden();
        $this->getJson('/_inertia/devtools/entries/' . $this->savedEntryId())->assertForbidden();
    }

    public function testTheLocalEnvironmentIsAllowedEvenWhenTheGateFails(): void
    {
        $this->app->instance('env', 'local');

        // Entries are a local development tool: a gate that fails locally, e.g. because the
        // developer is not signed in, must not lock them out of their own devtools.
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => false);

        $this->getJson('/_inertia/devtools/entries')->assertOk();
    }

    public function testTheGateReceivesTheAuthenticatedUser(): void
    {
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => $user?->getAuthIdentifier() === 42);

        $this->getJson('/_inertia/devtools/entries')->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 42]))
            ->getJson('/_inertia/devtools/entries')
            ->assertOk();
    }

    public function testTheGateRunsWithTheSessionStarted(): void
    {
        // Without the session the `web` group starts, a gate that authenticates the
        // user could never allow anyone in.
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => request()->hasSession());

        $this->getJson('/_inertia/devtools/entries')->assertOk();
    }

    /**
     * Save an entry and return its id.
     */
    protected function savedEntryId(): string
    {
        $id = (string) Str::ulid();

        $this->repo->save($id, ['__meta' => ['id' => $id]]);

        return $id;
    }
}
