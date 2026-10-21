<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Http\Events\RequestHandled;
use Hypervel\Inertia\DevTools\DevToolsHeader;
use Hypervel\Inertia\DevTools\EntryStore;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\TestCase;

class MiddlewareDevToolsDisabledTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('inertia.devtools.enabled', false);
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

    public function testNothingIsRecordedWhenDevtoolsIsDisabled(): void
    {
        Route::middleware(Middleware::class)->get('/devtools-off', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $response = $this->get('/devtools-off');

        $response->assertOk();
        $this->assertNull($response->headers->get(DevToolsHeader::DEVTOOLS_ID));
        $this->assertStringNotContainsString('data-inertia-devtools-id', (string) $response->getContent());

        $this->app->make(EntryStore::class)->flush($this->repo);

        $this->assertSame([], $this->repo->all());
    }

    public function testTheEntryEndpointsAreNotRegisteredWhenDevtoolsIsDisabled(): void
    {
        $this->getJson('/_inertia/devtools/entries')->assertNotFound();
    }

    public function testNoRequestHandledListenerIsRegisteredWhenDevtoolsIsDisabled(): void
    {
        // The kernel only builds and dispatches the event when something listens for it.
        $this->assertFalse($this->app->make('events')->hasListeners(RequestHandled::class));
    }
}
