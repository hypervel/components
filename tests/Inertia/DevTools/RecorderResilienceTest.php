<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Inertia\DevTools\EntriesRepository;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Inertia\Response;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\TestCase;

class RecorderResilienceTest extends TestCase
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

    public function testAMisconfiguredExceptListDoesNotBreakTheResponse(): void
    {
        // Recording is a passive observer: a bad config value must not turn every request in
        // the app into a 500.
        config()->set('inertia.devtools.except', 'not-an-array');

        Route::middleware(Middleware::class)->get('/devtools-misconfigured', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        $this->get('/devtools-misconfigured', ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('props.name', 'Alice');
    }

    public function testAnUnusableStorageDirectoryDoesNotBreakTheResponse(): void
    {
        // A file where the storage directory should be makes every write and prune fail.
        mkdir($this->devtoolsStoragePath, 0700, true);
        $blocked = $this->devtoolsStoragePath . DIRECTORY_SEPARATOR . 'blocked';
        touch($blocked);

        $this->app->instance(EntriesRepository::class, new EntriesRepository(path: $blocked . DIRECTORY_SEPARATOR . 'entries'));

        // The first failure trips the breaker, so the second request does not log again.
        Log::shouldReceive('warning')->once();

        Route::middleware(Middleware::class)->get('/devtools-unwritable', fn (): Response => Inertia::render('Users/Index', ['name' => 'Alice']));

        foreach ([1, 2] as $attempt) {
            $this->get('/devtools-unwritable', ['X-Inertia' => 'true'])
                ->assertOk()
                ->assertJsonPath('props.name', 'Alice');
        }
    }
}
