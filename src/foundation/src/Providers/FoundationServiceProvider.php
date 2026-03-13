<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Providers;

use Hypervel\Config\Repository;
use Hypervel\Console\Events\FailToHandle;
use Hypervel\Contracts\Auth\Factory as AuthFactoryContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Grammar;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\Console\ConfigShowCommand;
use Hypervel\Foundation\Console\DownCommand;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Console\RouteCacheCommand;
use Hypervel\Foundation\Console\RouteClearCommand;
use Hypervel\Foundation\Console\ServerReloadCommand;
use Hypervel\Foundation\Console\UpCommand;
use Hypervel\Foundation\Console\VendorPublishCommand;
use Hypervel\Foundation\Http\HtmlDumper;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Foundation\MaintenanceModeManager;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Uri;
use Hypervel\Validation\ValidationException;
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

        $events = $this->app->make('events');

        $events->listen(BeforeWorkerStart::class, function (BeforeWorkerStart $event) {
            $this->app->make(ReloadDotenvAndConfig::class)->handle($event);
        });

        $this->publishes([
            __DIR__ . '/../../config/app.php' => config_path('app.php'),
        ], 'app-config');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/app.php', 'app');

        $this->listenCommandException();
        $this->registerMaintenanceModeManager();
        $this->registerRequestValidation();
        $this->registerRequestSignatureValidation();
        $this->registerUriUrlGeneration();

        $this->registerDumper();

        $this->commands([
            AboutCommand::class,
            ConfigShowCommand::class,
            DownCommand::class,
            RouteCacheCommand::class,
            RouteClearCommand::class,
            ServerReloadCommand::class,
            UpCommand::class,
            VendorPublishCommand::class,
        ]);

        $this->callAfterResolving(Request::class, function (Request $request) {
            $request->setUserResolver(function (?string $guard = null) {
                return $this->app
                    ->make(AuthFactoryContract::class)
                    ->guard($guard)
                    ->user();
            });
        });
    }

    protected function listenCommandException(): void
    {
        $this->app->make(Dispatcher::class)
            ->listen(FailToHandle::class, function ($event) {
                // During tests, PendingCommand handles command exceptions itself
                // (capturing via its own FailToHandle listener and re-throwing).
                // Rendering here would produce duplicate, unwanted output to stdout.
                if ($this->app->runningUnitTests()) {
                    return;
                }

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

    /**
     * Register the "validate" macro on the request.
     *
     * @throws ValidationException
     */
    protected function registerRequestValidation(): void
    {
        Request::macro('validate', function (array $rules, ...$params) {
            return tap(validator($this->all(), $rules, ...$params), function ($validator) {
                if ($this->isPrecognitive()) {
                    $validator->after(\Hypervel\Foundation\Precognition::afterValidationHook($this))
                        ->setRules(
                            $this->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
                        );
                }
            })->validate();
        });

        Request::macro('validateWithBag', function (string $errorBag, array $rules, ...$params) {
            try {
                return $this->validate($rules, ...$params);
            } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown ($this->validate() is a macro that throws ValidationException)
                $e->errorBag = $errorBag;

                throw $e;
            }
        });
    }

    /**
     * Register the "hasValidSignature" macro on the request.
     */
    protected function registerRequestSignatureValidation(): void
    {
        Request::macro('hasValidSignature', function ($absolute = true) {
            return URL::hasValidSignature($this, $absolute);
        });

        Request::macro('hasValidRelativeSignature', function () {
            return URL::hasValidSignature($this, $absolute = false);
        });

        Request::macro('hasValidSignatureWhileIgnoring', function ($ignoreQuery = [], $absolute = true) {
            return URL::hasValidSignature($this, $absolute, $ignoreQuery);
        });

        Request::macro('hasValidRelativeSignatureWhileIgnoring', function ($ignoreQuery = []) {
            return URL::hasValidSignature($this, $absolute = false, $ignoreQuery);
        });
    }

    /**
     * Register the maintenance mode manager and its caching decorator.
     */
    protected function registerMaintenanceModeManager(): void
    {
        $this->app->singleton(
            MaintenanceModeContract::class,
            fn () => new WorkerCachedMaintenanceMode(
                $this->app->make(MaintenanceModeManager::class)->driver()
            )
        );
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

        $compiledViewPath = $this->config->get('view.compiled');

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
