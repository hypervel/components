<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools;

use Hypervel\Foundation\Http\Events\RequestHandled;
use Hypervel\Inertia\DevTools\Http\Authorize;
use Hypervel\Inertia\DevTools\Http\EntriesController;
use Hypervel\Inertia\DevTools\Http\PreserveFlashData;
use Hypervel\Inertia\DevTools\Http\PreventPreviousUrlTracking;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class DevToolsServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->scoped(EntryStore::class, fn (): EntryStore => new EntryStore);

        $this->app->scoped(SourceLocator::class, fn (): SourceLocator => new SourceLocator);

        // Scoped rather than auto-singleton: the builder holds the request's source locator.
        $this->app->scoped(IncomingEntryBuilder::class);

        $this->app->singleton(EntriesRepository::class, function (): EntriesRepository {
            return new EntriesRepository(
                path: config()->string('inertia.devtools.storage.path', storage_path('inertia-devtools')),
                autoPruneHours: config()->integer('inertia.devtools.storage.ttl', 24),
            );
        });

        // Scoped: the recorder holds per-request collection state across the lifecycle
        // callbacks. It self-disables (every method no-ops) when devtools is off.
        $this->app->scoped(RequestRecorder::class, fn (): RequestRecorder => new RequestRecorder);
    }

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        if (! DevTools::enabled()) {
            return;
        }

        // Only requests are recorded, so the entry is flushed once the request has been handled,
        // before the response is sent, so the extension can fetch it when the headers arrive.
        $this->app->make('events')->listen(RequestHandled::class, function (): void {
            $this->app->make(EntryStore::class)->flush($this->app->make(EntriesRepository::class));
        });

        $middleware = [
            PreventPreviousUrlTracking::class,
            ...$this->routeMiddleware(),
            PreserveFlashData::class,
            Authorize::class,
        ];

        Route::middleware($middleware)
            ->prefix('_inertia/devtools')
            ->group(function (): void {
                Route::get('entries', [EntriesController::class, 'index']);
                Route::get('entries/{id}', [EntriesController::class, 'show']);
            });
    }

    /**
     * The middleware the entry endpoints run before they are authorized. Defaults to the
     * `web` group so the gate may authorize the user from the session it starts.
     *
     * @return array<int, mixed>
     */
    protected function routeMiddleware(): array
    {
        return Arr::wrap($this->app->make('config')->get('inertia.devtools.middleware', ['web']));
    }
}
