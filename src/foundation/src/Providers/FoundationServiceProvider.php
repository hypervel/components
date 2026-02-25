<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Providers;

use Hypervel\Config\Repository;
use Hypervel\Console\Events\FailToHandle;
use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Router\UrlGenerator as UrlGeneratorContract;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Grammar;
use Hypervel\ExceptionHandler\Listener\ErrorExceptionHandler;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\Console\Commands\AboutCommand;
use Hypervel\Foundation\Console\Commands\ConfigShowCommand;
use Hypervel\Foundation\Console\Commands\ServerReloadCommand;
use Hypervel\Foundation\Console\Commands\VendorPublishCommand;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Http\Contracts\MiddlewareContract;
use Hypervel\Foundation\Http\HtmlDumper;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Foundation\Listeners\SetProcessTitle;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\HttpServer\MiddlewareManager;
use Hypervel\Server\Listener\InitProcessTitleListener;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Uri;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\VarDumper\Caster\StubCaster;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Throwable;

class FoundationServiceProvider extends ServiceProvider
{
    protected Repository $config;

    protected ConsoleOutputInterface $output;

    public function __construct(protected ApplicationContract $app)
    {
        $this->config = $app->make('config');
        $this->output = new ConsoleOutput();

        if ($app->hasDebugModeEnabled()) {
            $this->output->setVerbosity(ConsoleOutputInterface::VERBOSITY_VERBOSE);
        }
    }

    public function boot(): void
    {
        $this->setDefaultTimezone();
        $this->setInternalEncoding();
        $this->setDatabaseConnection();

        $this->app->make(ErrorExceptionHandler::class)->process(new BootApplication());

        $events = $this->app->make('events');

        $events->listen(BeforeWorkerStart::class, function (BeforeWorkerStart $event) {
            $this->app->make(ReloadDotenvAndConfig::class)->process($event);
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->overrideHyperfConfigs();
        $this->listenCommandException();
        $this->registerUriUrlGeneration();

        $this->registerDumper();

        $this->app->singleton(InitProcessTitleListener::class, SetProcessTitle::class);

        $this->commands([
            AboutCommand::class,
            ConfigShowCommand::class,
            ServerReloadCommand::class,
            VendorPublishCommand::class,
        ]);

        $this->callAfterResolving(RequestContract::class, function (RequestContract $request) {
            $request->setUserResolver(function (?string $guard = null) {
                return $this->app
                    ->get(AuthFactoryContract::class)
                    ->guard($guard)
                    ->user();
            });
        });
    }

    protected function listenCommandException(): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(FailToHandle::class, function ($event) {
                if ($this->isConsoleKernelCall($throwable = $event->getThrowable())) {
                    /** @var \Hypervel\Console\Application $artisan */
                    $artisan = $this->app->make(ConsoleKernel::class)->getArtisan();
                    $artisan->renderThrowable($throwable, $this->output);
                }
            });
    }

    protected function isConsoleKernelCall(Throwable $exception): bool
    {
        foreach ($exception->getTrace() as $trace) {
            if (($trace['class'] ?? null) === ConsoleKernel::class
                && ($trace['function'] ?? null) === 'call') { // @phpstan-ignore nullCoalesce.offset (defensive backtrace handling)
                return true;
            }
        }

        return false;
    }

    protected function setDatabaseConnection(): void
    {
        $connection = $this->config->get('database.default', 'mysql');
        $this->app->make(ConnectionResolverInterface::class)
            ->setDefaultConnection($connection);
    }

    protected function overrideHyperfConfigs(): void
    {
        $configs = [
            'app_name' => $this->config->get('app.name'),
            'app_env' => $this->config->get('app.env'),
            StdoutLoggerInterface::class . '.log_level' => $this->config->get('app.stdout_log_level'),
        ];

        foreach ($configs as $key => $value) {
            if (! $this->config->has($key)) {
                $this->config->set($key, $value);
            }
        }

        $this->config->set('middlewares', $this->getMiddlewareConfig());
    }

    protected function getMiddlewareConfig(): array
    {
        if ($middleware = $this->config->get('middlewares', [])) {
            foreach ($middleware as $server => $middlewareConfig) {
                $middleware[$server] = MiddlewareManager::sortMiddlewares($middlewareConfig);
            }
        }

        foreach ($this->config->get('server.kernels', []) as $server => $kernel) {
            if (! is_string($kernel) || ! is_a($kernel, MiddlewareContract::class, true)) {
                continue;
            }
            $middleware[$server] = array_merge(
                $this->app->make($kernel)->getGlobalMiddleware(),
                $middleware[$server] ?? [],
            );
        }

        return $middleware;
    }

    protected function registerUriUrlGeneration(): void
    {
        Uri::setUrlGeneratorResolver(
            fn () => $this->app->make(UrlGeneratorContract::class)
        );
    }

    protected function setDefaultTimezone(): void
    {
        date_default_timezone_set($this->config->get('app.timezone', 'UTC'));
    }

    protected function setInternalEncoding(): void
    {
        mb_internal_encoding('UTF-8');
    }

    protected function registerDumper(): void
    {
        AbstractCloner::$defaultCasters[ConnectionInterface::class] ??= [StubCaster::class, 'cutInternals'];
        AbstractCloner::$defaultCasters[Container::class] ??= [StubCaster::class, 'cutInternals'];
        AbstractCloner::$defaultCasters[Dispatcher::class] ??= [StubCaster::class, 'cutInternals'];
        AbstractCloner::$defaultCasters[Grammar::class] ??= [StubCaster::class, 'cutInternals'];

        $basePath = $this->app->basePath();

        $compiledViewPath = $this->config->get('view.config.view_path');

        $format = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;

        match (true) {
            $format == 'html' => HtmlDumper::register($basePath, $compiledViewPath),
            $format == 'cli' => CliDumper::register($basePath, $compiledViewPath),
            $format == 'server' => null,
            $format && parse_url($format, PHP_URL_SCHEME) == 'tcp' => null,
            default => php_sapi_name() === 'cli' ? CliDumper::register($basePath, $compiledViewPath) : HtmlDumper::register($basePath, $compiledViewPath),
        };
    }
}
