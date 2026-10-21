<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Http\Events\RequestHandled;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Inertia\DevTools\Data\IncomingEntry;
use Hypervel\Inertia\DevTools\EntryStore;
use Hypervel\Inertia\DevTools\IncomingEntryBuilder;
use Hypervel\Inertia\DevTools\SourceLocator;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Response as InertiaResponse;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\TestCase;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    // Like a server's request coroutines, tests see only what InertiaState replicates from
    // the boot baseline, not a copy of the whole non-coroutine context.
    protected bool $copyNonCoroutineContext = false;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('inertia.devtools.enabled', true);
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindEntriesRepository();

        // Shared outside a coroutine, as a service provider does during boot.
        Inertia::share('booted', 'value');
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->clearDevToolsStorage();

        parent::tearDown();
    }

    public function testEntriesRecordedByConcurrentRequestsAreFlushedWhenEachRequestIsHandled(): void
    {
        [$first, $second] = parallel([
            fn (): array => $this->recordAndHandle('Users/Index'),
            fn (): array => $this->recordAndHandle('Posts/Index'),
        ]);

        $this->assertEqualsCanonicalizing(
            ['Users/Index', 'Posts/Index'],
            array_column($this->repo->all(), 'component'),
        );

        // Each request records through its own store, entry builder and source locator.
        foreach (['store', 'builder', 'locator'] as $service) {
            $this->assertNotSame($first[$service], $second[$service]);
        }
    }

    public function testShareSourcesFromBootReachEachRequest(): void
    {
        Route::middleware(Middleware::class)->get('/boot-share-route', fn (): InertiaResponse => Inertia::render('Users/Index'));

        $this->get('/boot-share-route', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

        $this->app->make(EntryStore::class)->flush($this->repo);

        $prop = $this->latestRecordedEntry()['props']['booted'];

        $this->assertTrue($prop['shared']);
        $this->assertSame(__FILE__, $prop['shareSource']['file']);
    }

    public function testShareSourcesRecordedDuringARequestStayInThatRequest(): void
    {
        [$first, $second] = parallel([
            function (): array {
                Inertia::share('flash', 'first');
                usleep(5000);

                return InertiaState::current()->shareSources;
            },
            function (): array {
                Inertia::share('flash', 'second');
                usleep(5000);

                return InertiaState::current()->shareSources;
            },
        ]);

        $lines = file(__FILE__);

        $this->assertStringContainsString("'first'", $lines[$first['flash']['line'] - 1]);
        $this->assertStringContainsString("'second'", $lines[$second['flash']['line'] - 1]);
        $this->assertArrayHasKey('booted', $first);
        $this->assertArrayHasKey('booted', $second);
    }

    /**
     * Record an entry and finish the request in the current coroutine.
     *
     * @return array{store: EntryStore, builder: IncomingEntryBuilder, locator: SourceLocator}
     */
    protected function recordAndHandle(string $component): array
    {
        $entry = new IncomingEntry;
        $entry->component = $component;

        $store = $this->app->make(EntryStore::class);
        $store->record($entry);

        // Let the other request record its entry before this one is handled.
        usleep(5000);

        $this->app->make('events')->dispatch(new RequestHandled(Request::create('/'), new Response('ok')));

        return [
            'store' => $store,
            'builder' => $this->app->make(IncomingEntryBuilder::class),
            'locator' => $this->app->make(SourceLocator::class),
        ];
    }
}
