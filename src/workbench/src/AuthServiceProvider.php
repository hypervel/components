<?php

declare(strict_types=1);

namespace Hypervel\Workbench;

use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Foundation\Events\ServeCommandStarted;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\View\Factory as ViewFactory;
use Hypervel\Workbench\Listeners\PublishAssets;
use Hypervel\Workbench\View\Components\AppLayout;
use Hypervel\Workbench\View\Components\GuestLayout;
use Override;

use function Hypervel\Filesystem\join_paths;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->booted(function (): void {
            $this->loadRoutesFrom((string) realpath(join_paths(__DIR__, '..', 'routes', 'workbench-auth.php')));
        });

        $this->loadViewsFrom((string) realpath(join_paths(__DIR__, '..', 'resources', 'views')), '');

        $this->loadViewComponentsAs('', [
            AppLayout::class,
            GuestLayout::class,
        ]);

        $this->loadAnonymousComponentsFrom((string) realpath(join_paths(__DIR__, '..', 'resources', 'views', 'components')));

        // Testbench serves with console mode switched off, so the publish map and
        // serve listener are registered without upstream's runningInConsole() guard.
        $this->publishes([
            join_paths(__DIR__, '..', 'public', 'build') => public_path(Workbench::BUILD_DIRECTORY),
        ], ['hypervel-assets']);

        $this->app->make('events')->listen(ServeCommandStarted::class, function (ServeCommandStarted $event): void {
            $this->app->make(PublishAssets::class)->handle($event);
        });
    }

    /**
     * Register a view file namespace.
     */
    #[Override]
    protected function loadViewsFrom(array|string $path, string $namespace): void
    {
        if (empty($namespace)) {
            $this->callAfterResolving('view', static function (ViewFactory $view) use ($path): void {
                foreach ((array) $path as $location) {
                    $view->getFinder()->addLocation($location);
                }
            });
        }

        parent::loadViewsFrom($path, $namespace);
    }

    /**
     * Register the given view components with a custom prefix.
     */
    protected function loadAnonymousComponentsFrom(string $path, ?string $prefix = null): void
    {
        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade) use ($path, $prefix): void {
            $blade->anonymousComponentPath($path, $prefix);
        });
    }
}
