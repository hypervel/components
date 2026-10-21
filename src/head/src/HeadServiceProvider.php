<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Head\Inertia\Integration as InertiaIntegration;
use Hypervel\Head\Rendering\HeadRenderer;
use Hypervel\Head\Routing\HeadRouteMacros;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class HeadServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentHead::class);

        $this->app->singleton('head.schema', fn (): SchemaFactory => new SchemaFactory);
        $this->app->alias('head.schema', SchemaFactory::class);

        $this->app->singleton('head', fn (Container $app): HeadManager => new HeadManager(
            $app,
            $app->make(HeadRenderer::class),
            $app->make(TagRegistry::class),
        ));
        $this->app->alias('head', HeadManager::class);
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        Blade::directive(
            'head',
            fn (string $expression): string => $expression === ''
            ? "<?php echo app('head')->renderForView(); ?>"
            : "<?php echo app('head')->renderForView({$expression}); ?>"
        );

        (new HeadRouteMacros)->register();

        $this->registerExceptionStatusResolver();

        // All providers must be registered before checking whether Inertia is loaded.
        (new InertiaIntegration($this->app))->register();
    }

    /**
     * Capture error status before Blade views and final response customization.
     */
    protected function registerExceptionStatusResolver(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $head = $this->app->make(HeadManager::class);

            if (is_callable([$handler, 'renderable'])) {
                $handler->renderable(function (Throwable $exception, Request $request) use ($head): void {
                    $head->status(
                        $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500
                    );
                });
            }

            if (is_callable([$handler, 'beforeResponding'])) {
                $handler->beforeResponding(function (Response $response) use ($head): void {
                    $head->status($response->getStatusCode());
                });
            }
        });
    }
}
