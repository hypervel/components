<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Providers;

use Hypervel\Config\Repository;
use Hypervel\Console\Events\CommandFinished;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Foundation\ExceptionRenderer;
use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Grammar;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Foundation\Console\ApiInstallCommand;
use Hypervel\Foundation\Console\BroadcastingInstallCommand;
use Hypervel\Foundation\Console\CastMakeCommand;
use Hypervel\Foundation\Console\ChannelListCommand;
use Hypervel\Foundation\Console\ChannelMakeCommand;
use Hypervel\Foundation\Console\ClassMakeCommand;
use Hypervel\Foundation\Console\ClearCompiledCommand;
use Hypervel\Foundation\Console\CliDumper;
use Hypervel\Foundation\Console\ComponentMakeCommand;
use Hypervel\Foundation\Console\ConfigCacheCommand;
use Hypervel\Foundation\Console\ConfigClearCommand;
use Hypervel\Foundation\Console\ConfigMakeCommand;
use Hypervel\Foundation\Console\ConfigPublishCommand;
use Hypervel\Foundation\Console\ConfigShowCommand;
use Hypervel\Foundation\Console\ConsoleMakeCommand;
use Hypervel\Foundation\Console\DownCommand;
use Hypervel\Foundation\Console\EnumMakeCommand;
use Hypervel\Foundation\Console\EnvironmentCommand;
use Hypervel\Foundation\Console\EnvironmentDecryptCommand;
use Hypervel\Foundation\Console\EnvironmentEncryptCommand;
use Hypervel\Foundation\Console\EventCacheCommand;
use Hypervel\Foundation\Console\EventClearCommand;
use Hypervel\Foundation\Console\EventGenerateCommand;
use Hypervel\Foundation\Console\EventListCommand;
use Hypervel\Foundation\Console\EventMakeCommand;
use Hypervel\Foundation\Console\ExceptionMakeCommand;
use Hypervel\Foundation\Console\InterfaceMakeCommand;
use Hypervel\Foundation\Console\InvokeSerializedClosureCommand;
use Hypervel\Foundation\Console\JobMakeCommand;
use Hypervel\Foundation\Console\JobMiddlewareMakeCommand;
use Hypervel\Foundation\Console\LangPublishCommand;
use Hypervel\Foundation\Console\ListenerMakeCommand;
use Hypervel\Foundation\Console\MailMakeCommand;
use Hypervel\Foundation\Console\ModelMakeCommand;
use Hypervel\Foundation\Console\NotificationMakeCommand;
use Hypervel\Foundation\Console\ObserverMakeCommand;
use Hypervel\Foundation\Console\OptimizeClearCommand;
use Hypervel\Foundation\Console\OptimizeCommand;
use Hypervel\Foundation\Console\PackageDiscoverCommand;
use Hypervel\Foundation\Console\PolicyMakeCommand;
use Hypervel\Foundation\Console\ProviderMakeCommand;
use Hypervel\Foundation\Console\ReloadCommand;
use Hypervel\Foundation\Console\RequestMakeCommand;
use Hypervel\Foundation\Console\ResourceMakeCommand;
use Hypervel\Foundation\Console\RouteCacheCommand;
use Hypervel\Foundation\Console\RouteClearCommand;
use Hypervel\Foundation\Console\RouteListCommand;
use Hypervel\Foundation\Console\RuleMakeCommand;
use Hypervel\Foundation\Console\ScopeMakeCommand;
use Hypervel\Foundation\Console\StorageLinkCommand;
use Hypervel\Foundation\Console\StorageUnlinkCommand;
use Hypervel\Foundation\Console\StubPublishCommand;
use Hypervel\Foundation\Console\TestMakeCommand;
use Hypervel\Foundation\Console\TraitMakeCommand;
use Hypervel\Foundation\Console\UpCommand;
use Hypervel\Foundation\Console\VendorPublishCommand;
use Hypervel\Foundation\Console\ViewCacheCommand;
use Hypervel\Foundation\Console\ViewClearCommand;
use Hypervel\Foundation\Console\ViewMakeCommand;
use Hypervel\Foundation\Exceptions\Renderer\Listener;
use Hypervel\Foundation\Exceptions\Renderer\Mappers\BladeMapper;
use Hypervel\Foundation\Exceptions\Renderer\Renderer;
use Hypervel\Foundation\Http\HtmlDumper;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Foundation\MaintenanceModeManager;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Http\Request;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Support\Composer;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Uri;
use Hypervel\Testing\LoggedExceptionCollection;
use Hypervel\Validation\ValidationException;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\VarDumper\Caster\StubCaster;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;

class FoundationServiceProvider extends ServiceProvider
{
    protected Repository $config;

    public function __construct(protected ApplicationContract $app)
    {
        $this->config = $app->make('config');
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

        if ($this->app->hasDebugModeEnabled() && ! $this->app->has(ExceptionRenderer::class)) {
            $this->app->make(Listener::class)->registerListeners(
                $this->app->make(Dispatcher::class)
            );
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Exceptions/views' => $this->app->resourcePath('views/errors/'),
            ], 'hypervel-errors');
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('composer', fn ($app) => new Composer(
            $app['files'],
            $app->basePath()
        ));

        $this->registerDeferHandler();
        $this->registerConsoleSchedule();
        $this->registerMaintenanceModeManager();
        $this->registerRequestValidation();
        $this->registerRequestSignatureValidation();
        $this->registerUriUrlGeneration();

        $this->registerDumper();
        $this->registerExceptionTracking();
        $this->registerExceptionRenderer();

        $this->commands([
            AboutCommand::class,
            ApiInstallCommand::class,
            BroadcastingInstallCommand::class,
            CastMakeCommand::class,
            ChannelListCommand::class,
            ChannelMakeCommand::class,
            ClearCompiledCommand::class,
            ClassMakeCommand::class,
            ComponentMakeCommand::class,
            ConfigCacheCommand::class,
            ConfigClearCommand::class,
            ConfigMakeCommand::class,
            ConfigPublishCommand::class,
            ConfigShowCommand::class,
            ConsoleMakeCommand::class,
            DownCommand::class,
            EnvironmentCommand::class,
            EnvironmentDecryptCommand::class,
            EnvironmentEncryptCommand::class,
            EnumMakeCommand::class,
            EventCacheCommand::class,
            EventClearCommand::class,
            EventGenerateCommand::class,
            EventListCommand::class,
            EventMakeCommand::class,
            ExceptionMakeCommand::class,
            InterfaceMakeCommand::class,
            InvokeSerializedClosureCommand::class,
            JobMakeCommand::class,
            JobMiddlewareMakeCommand::class,
            LangPublishCommand::class,
            ListenerMakeCommand::class,
            MailMakeCommand::class,
            ModelMakeCommand::class,
            NotificationMakeCommand::class,
            ObserverMakeCommand::class,
            OptimizeCommand::class,
            OptimizeClearCommand::class,
            PackageDiscoverCommand::class,
            PolicyMakeCommand::class,
            ProviderMakeCommand::class,
            ReloadCommand::class,
            RequestMakeCommand::class,
            ResourceMakeCommand::class,
            RouteCacheCommand::class,
            RouteClearCommand::class,
            RouteListCommand::class,
            RuleMakeCommand::class,
            ScopeMakeCommand::class,
            StorageLinkCommand::class,
            StorageUnlinkCommand::class,
            StubPublishCommand::class,
            TestMakeCommand::class,
            TraitMakeCommand::class,
            UpCommand::class,
            VendorPublishCommand::class,
            ViewCacheCommand::class,
            ViewClearCommand::class,
            ViewMakeCommand::class,
        ]);
    }

    /**
     * Register the console schedule implementation.
     */
    protected function registerConsoleSchedule(): void
    {
        $this->app->singleton(Schedule::class, function ($app) {
            return $app->make(ConsoleKernelContract::class)->resolveConsoleSchedule();
        });
    }

    /**
     * Register the defer lifecycle handlers.
     */
    protected function registerDeferHandler(): void
    {
        $this->app->scoped(DeferredCallbackCollection::class);

        $this->app['events']->listen(function (CommandFinished $event) {
            $this->app->make(DeferredCallbackCollection::class)
                ->invokeWhen(fn (DeferredCallback $callback) => $this->app->runningInConsole() && ($event->exitCode === 0 || $callback->always));
        });

        $this->app['events']->listen(function (JobAttempted $event) {
            if (in_array($event->connectionName, ['sync', 'deferred'], true)) {
                return;
            }

            $this->app->make(DeferredCallbackCollection::class)
                ->invokeWhen(fn (DeferredCallback $callback) => $event->successful() || $callback->always);
        });
    }

    protected function setDatabaseConnection(): void
    {
        $connection = $this->config->get('database.default', 'mysql');
        $this->app->make('db')
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

    /**
     * Register the exception tracking for tests.
     */
    protected function registerExceptionTracking(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        $this->app->instance(
            LoggedExceptionCollection::class,
            new LoggedExceptionCollection
        );

        $this->app->make('events')->listen(MessageLogged::class, function ($event) {
            if (isset($event->context['exception'])) {
                $this->app->make(LoggedExceptionCollection::class)
                    ->push($event->context['exception']);
            }
        });
    }

    /**
     * Register the exception renderer.
     */
    protected function registerExceptionRenderer(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Exceptions/views', 'hypervel-exceptions');

        if (! $this->app->hasDebugModeEnabled()) {
            return;
        }

        $this->loadViewsFrom(
            __DIR__ . '/../../resources/exceptions/renderer',
            'hypervel-exceptions-renderer'
        );

        $this->app->singleton(Renderer::class, function () {
            $errorRenderer = new HtmlErrorRenderer(
                $this->config->get('app.debug'),
            );

            return new Renderer(
                $this->app->make(ViewFactory::class),
                $this->app->make(Listener::class),
                $errorRenderer,
                $this->app->make(BladeMapper::class),
                $this->app->basePath(),
            );
        });

        $this->app->singleton(Listener::class);
    }

    protected function registerUriUrlGeneration(): void
    {
        Uri::setUrlGeneratorResolver(
            fn () => app('url')
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
