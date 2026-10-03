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
use Hypervel\Tests\Inertia\TestCase;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    use InteractsWithDevToolsStorage;

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
