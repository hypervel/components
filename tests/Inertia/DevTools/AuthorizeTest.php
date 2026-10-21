<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Str;
use Hypervel\Tests\Inertia\TestCase;

class AuthorizeTest extends TestCase
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
        $config->set('inertia.devtools.gate', null);
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindEntriesRepository();
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->clearDevToolsStorage();

        parent::tearDown();
    }

    public function testTheLocalEnvironmentIsAllowedWithoutAGate(): void
    {
        $this->environment('local');

        $this->getJson('/_inertia/devtools/entries')->assertOk();
        $this->getJson('/_inertia/devtools/entries/' . $this->savedEntryId())->assertOk();
    }

    public function testOtherEnvironmentsAreDeniedWithoutAGate(): void
    {
        $this->environment('production');

        $this->getJson('/_inertia/devtools/entries')->assertForbidden();
        $this->getJson('/_inertia/devtools/entries/' . $this->savedEntryId())->assertForbidden();
    }

    public function testPollingTheEntriesEndpointDoesNotBecomeThePreviousUrl(): void
    {
        $this->environment('local');

        $this->getJson('/_inertia/devtools/entries')->assertOk();

        // The extension polls these endpoints in the background. A recorded previous URL
        // would become the target of the app's next `back()` redirect.
        $this->assertNull($this->app->make('session')->previousUrl());
    }

    /**
     * Switch the application environment.
     */
    protected function environment(string $environment): void
    {
        $this->app->instance('env', $environment);
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
