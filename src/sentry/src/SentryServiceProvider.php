<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Http\Kernel as HttpKernelInterface;
use Hypervel\Contracts\View\Engine;
use Hypervel\Contracts\View\View;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Http\Request;
use Hypervel\Routing\Contracts\CallableDispatcher;
use Hypervel\Routing\Contracts\ControllerDispatcher;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\Console\AboutCommandIntegration;
use Hypervel\Sentry\Console\PublishCommand;
use Hypervel\Sentry\Console\TestCommand;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\Http\FlushEventsMiddleware;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Sentry\Http\SetRequestIpMiddleware;
use Hypervel\Sentry\Integration\ContextIntegration;
use Hypervel\Sentry\Integration\ExceptionContextIntegration;
use Hypervel\Sentry\Tracing\BacktraceHelper;
use Hypervel\Sentry\Tracing\EventHandler as TracingEventHandler;
use Hypervel\Sentry\Tracing\Middleware as TracingMiddleware;
use Hypervel\Sentry\Tracing\Routing\TracingCallableDispatcherTracing;
use Hypervel\Sentry\Tracing\Routing\TracingControllerDispatcherTracing;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Support\ServiceProvider;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory as ViewFactory;
use InvalidArgumentException;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Integration as SdkIntegration;
use Sentry\Logger\DebugFileLogger;
use Sentry\Logs\Logs;
use Sentry\SentrySdk;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\State\HubInterface;
use Throwable;

class SentryServiceProvider extends ServiceProvider
{
    /**
     * Configuration options that are Hypervel-specific and should not be sent to the base PHP SDK.
     */
    protected const HYPERVEL_SPECIFIC_OPTIONS = [
        // These settings are Hypervel-specific and the PHP SDK will throw errors if it receives them
        'tracing',
        'breadcrumbs',
        'features',
        'pool',
        'ignore_commands',
        // We resolve the integrations through the container later, so we initially do not pass it to the SDK yet
        'integrations',
        // We have this setting to allow us to capture the .env LOG_LEVEL for the sentry_logs channel
        'logs_channel_level',
        // Kept for backwards compatibility
        'breadcrumbs.sql_bindings',
    ];

    /**
     * Options that should be resolved from the container instead of being passed directly to the SDK.
     */
    protected const OPTIONS_TO_RESOLVE_FROM_CONTAINER = [
        'logger',
    ];

    /**
     * The abstract type to bind Sentry as in the service container.
     */
    public static string $abstract = 'sentry';

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        // Eagerly resolve the Hub so SentrySdk has it available globally
        $this->app->make(HubInterface::class);

        $this->bootFeatures();

        // Only register event/middleware/tracing if a DSN is set or Spotlight is enabled.
        // No events can be sent without a DSN or Spotlight.
        if ($this->hasDsnSet() || $this->hasSpotlightEnabled()) {
            $this->bindEvents();
            $this->registerMiddleware();
            $this->bootTracing();
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }

        $this->registerAboutCommandIntegration();

        $this->registerCoroutineContextPropagation();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sentry.php', static::$abstract);

        $this->app->singleton(DebugFileLogger::class, function () {
            return new DebugFileLogger(storage_path('logs/sentry.log'));
        });

        $this->configureAndRegisterClient();

        $this->registerFeatures();

        $this->registerLogChannels();

        $this->aspects(GuzzleHttpClientAspect::class);
    }

    /**
     * Configure and register the Sentry client with the container.
     */
    protected function configureAndRegisterClient(): void
    {
        // ClientBuilder — fresh per resolution so each Hub gets a properly configured builder
        $this->app->bind(ClientBuilder::class, function () {
            $basePath = base_path();
            $userConfig = $this->getUserConfig();

            foreach (static::HYPERVEL_SPECIFIC_OPTIONS as $optionName) {
                unset($userConfig[$optionName]);
            }

            $options = array_merge(
                [
                    'prefixes' => [$basePath],
                    'in_app_exclude' => [
                        "{$basePath}/vendor",
                        "{$basePath}/artisan",
                    ],
                ],
                $userConfig
            );

            // Default to the application environment when not explicitly configured
            if (empty($options['environment'])) {
                $options['environment'] = $this->app->environment();
            }

            foreach (self::OPTIONS_TO_RESOLVE_FROM_CONTAINER as $option) {
                if (isset($options[$option]) && is_string($options[$option])) {
                    $options[$option] = $this->app->make($options[$option]);
                }
            }

            $clientBuilder = ClientBuilder::create($options);

            $clientBuilder->setSdkIdentifier(Version::SDK_IDENTIFIER);
            $clientBuilder->setSdkVersion(Version::SDK_VERSION);

            // Set the pooled transport for async sending via Swoole coroutines
            $poolConfig = $this->app->make('config')->get('sentry.pool', []);
            $transport = new HttpPoolTransport(
                new Pool($clientBuilder->getOptions(), $this->app, $poolConfig)
            );
            $clientBuilder->setTransport($transport);

            return $clientBuilder;
        });

        // HubInterface singleton — coroutine-scoped hub with full integration setup
        $this->app->singleton(HubInterface::class, function () {
            /** @var ClientBuilder $clientBuilder */
            $clientBuilder = $this->app->make(ClientBuilder::class);

            $options = $clientBuilder->getOptions();

            $userConfig = $this->getUserConfig();

            /** @var array<array-key, class-string>|callable $userIntegrationOption */
            $userIntegrationOption = $userConfig['integrations'] ?? [];

            $userIntegrations = $this->resolveIntegrationsFromUserConfig(
                is_array($userIntegrationOption) ? $userIntegrationOption : [],
                $userConfig['tracing']['default_integrations'] ?? true
            );

            $options->setIntegrations(static function (array $integrations) use ($options, $userIntegrations, $userIntegrationOption): array {
                if ($options->hasDefaultIntegrations()) {
                    // Remove the default error and fatal exception listeners to let the framework handle those
                    // through the exception handler and log channel integration
                    $integrations = array_filter($integrations, static function (SdkIntegration\IntegrationInterface $integration): bool {
                        if ($integration instanceof SdkIntegration\ErrorListenerIntegration) {
                            return false;
                        }

                        if ($integration instanceof SdkIntegration\ExceptionListenerIntegration) {
                            return false;
                        }

                        if ($integration instanceof SdkIntegration\FatalErrorListenerIntegration) {
                            return false;
                        }

                        // Remove the default request integration so it can be re-added with
                        // a Hypervel-specific request fetcher that reads from coroutine context
                        if ($integration instanceof SdkIntegration\RequestIntegration) {
                            return false;
                        }

                        return true;
                    });

                    $integrations[] = new SdkIntegration\RequestIntegration(
                        new HypervelRequestFetcher()
                    );
                }

                $integrations = array_merge(
                    $integrations,
                    [
                        new Integration(),
                        new ContextIntegration(),
                        new ExceptionContextIntegration(),
                    ],
                    $userIntegrations
                );

                if (is_callable($userIntegrationOption)) {
                    return $userIntegrationOption($integrations);
                }

                return $integrations;
            });

            $hub = new Hub($clientBuilder->getClient());

            SentrySdk::setCurrentHub($hub);

            return $hub;
        });

        $this->app->alias(HubInterface::class, static::$abstract);

        $this->app->singleton(BacktraceHelper::class, function () {
            $sentry = $this->app->make(HubInterface::class);

            $options = $sentry->getClient()->getOptions();

            return new BacktraceHelper($options, new RepresentationSerializer($options));
        });
    }

    /**
     * Bind to the event dispatcher to log events.
     */
    protected function bindEvents(): void
    {
        $userConfig = $this->getUserConfig();

        $handler = new EventHandler($this->app, $userConfig);

        try {
            /** @var \Hypervel\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->app->make('events');

            $handler->subscribe($dispatcher);

            if (isset($userConfig['send_default_pii']) && $userConfig['send_default_pii'] !== false) {
                $handler->subscribeAuthEvents($dispatcher);
            }

            if (isset($userConfig['enable_logs']) && $userConfig['enable_logs'] === true) {
                $this->app->terminating(static function () {
                    Logs::getInstance()->flush();
                });
            }
        } catch (BindingResolutionException) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Register HTTP middleware for Sentry.
     */
    protected function registerMiddleware(): void
    {
        if (! $this->app->bound(HttpKernelInterface::class)) {
            return;
        }

        $httpKernel = $this->app->make(HttpKernelInterface::class);

        if (! $httpKernel instanceof HttpKernel) {
            return;
        }

        // Tracing middleware is prepended so it starts the transaction as early as possible
        // in handle() and finishes the app span in terminate(). The transaction itself is
        // finished later by a Coroutine::defer() to capture after-response work.
        $httpKernel->prependMiddleware(TracingMiddleware::class);

        $httpKernel->pushMiddleware(SetRequestIpMiddleware::class);
        $httpKernel->pushMiddleware(FlushEventsMiddleware::class);
    }

    /**
     * Boot tracing-related services (view engine, routing dispatchers, event handler).
     */
    protected function bootTracing(): void
    {
        // Register the tracing middleware as scoped so each coroutine gets its own instance.
        // Per-request state ($transaction, $appSpan, $didRouteMatch) is isolated between concurrent requests.
        $this->app->scoped(TracingMiddleware::class);

        $this->app->booted(function () {
            TracingMiddleware::setBootedTimestamp();
        });

        $tracingConfig = $this->getUserConfig()['tracing'] ?? [];

        $this->bindTracingEvents($tracingConfig);
        $this->bindViewEngine($tracingConfig);
        $this->decorateRoutingDispatchers();
    }

    /**
     * Subscribe to framework events for tracing spans.
     */
    private function bindTracingEvents(array $tracingConfig): void
    {
        $handler = new TracingEventHandler($tracingConfig);

        try {
            /** @var \Hypervel\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->app->make('events');

            $handler->subscribe($dispatcher);
        } catch (BindingResolutionException) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Wrap the view engine to add tracing spans for view rendering.
     */
    private function bindViewEngine(array $tracingConfig): void
    {
        if (($tracingConfig['views'] ?? true) !== true) {
            return;
        }

        $viewEngineWrapper = function (EngineResolver $engineResolver): void {
            foreach (['file', 'php', 'blade'] as $engineName) {
                try {
                    $realEngine = $engineResolver->resolve($engineName);

                    // Prevent double wrapping — causes issues in framework internals
                    if ($realEngine instanceof ViewEngineDecorator) {
                        continue;
                    }

                    $engineResolver->register($engineName, function () use ($realEngine) {
                        return $this->wrapViewEngine($realEngine);
                    });
                } catch (InvalidArgumentException) {
                    // Engine doesn't exist, skip it
                }
            }
        };

        if ($this->app->resolved('view.engine.resolver')) {
            $viewEngineWrapper($this->app->make('view.engine.resolver'));
        } else {
            $this->app->afterResolving('view.engine.resolver', $viewEngineWrapper);
        }
    }

    /**
     * Wrap a view engine with the tracing decorator.
     */
    private function wrapViewEngine(Engine $realEngine): Engine
    {
        /** @var ViewFactory $viewFactory */
        $viewFactory = $this->app->make('view');

        $viewFactory->composer('*', static function (View $view) use ($viewFactory): void {
            $viewFactory->share(ViewEngineDecorator::SHARED_KEY, $view->name());
        });

        return new ViewEngineDecorator($realEngine, $viewFactory);
    }

    /**
     * Decorate the routing dispatchers with tracing wrappers.
     */
    private function decorateRoutingDispatchers(): void
    {
        $this->app->extend(CallableDispatcher::class, static function (CallableDispatcher $dispatcher) {
            return new TracingCallableDispatcherTracing($dispatcher);
        });

        $this->app->extend(ControllerDispatcher::class, static function (ControllerDispatcher $dispatcher) {
            return new TracingControllerDispatcherTracing($dispatcher);
        });
    }

    /**
     * Register the coroutine context propagation hook.
     *
     * Copies the Sentry scope stack and HTTP request context from parent to child
     * coroutines so that breadcrumbs, user context, and request data are available
     * in child coroutines (e.g., async jobs, parallel queries).
     */
    protected function registerCoroutineContextPropagation(): void
    {
        /* @phpstan-ignore-next-line */
        Coroutine::afterCreated(function () {
            $parentId = Coroutine::parentId();

            foreach ([Hub::CONTEXT_STACK_KEY, Request::class] as $key) {
                $value = CoroutineContext::get($key, null, $parentId);
                if ($value !== null) {
                    CoroutineContext::set($key, $value);
                }
            }
        });
    }

    /**
     * Register and bind all features.
     */
    protected function registerFeatures(): void
    {
        $features = $this->app->make('config')->get('sentry.features', []);

        foreach ($features as $feature) {
            $this->app->singleton($feature);
        }

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $featureInstance->register();
            } catch (Throwable) {
                // Ensure that features do not break the whole application
            }
        }
    }

    /**
     * Boot all features.
     */
    protected function bootFeatures(): void
    {
        $bootActive = $this->hasDsnSet() || $this->hasSpotlightEnabled();

        $features = $this->app->make('config')->get('sentry.features', []);

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $bootActive
                    ? $featureInstance->boot()
                    : $featureInstance->bootInactive();
            } catch (Throwable) {
                // Ensure that features do not break the whole application
            }
        }
    }

    /**
     * Register the sentry and sentry_logs log channels.
     */
    protected function registerLogChannels(): void
    {
        $config = $this->app->make('config');

        $logChannels = $config->get('logging.channels', []);

        if (! array_key_exists('sentry', $logChannels)) {
            $config->set('logging.channels.sentry', [
                'driver' => 'sentry',
            ]);
        }

        if (! array_key_exists('sentry_logs', $logChannels)) {
            $config->set('logging.channels.sentry_logs', [
                'driver' => 'sentry_logs',
                'level' => $config->get('sentry.logs_channel_level', 'debug'),
            ]);
        }
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sentry.php' => config_path('sentry.php'),
        ], 'sentry-config');
    }

    /**
     * Register the package's commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PublishCommand::class,
            TestCommand::class,
        ]);
    }

    /**
     * Register the `php artisan about` command integration.
     */
    protected function registerAboutCommandIntegration(): void
    {
        AboutCommand::add('Sentry', AboutCommandIntegration::class);
    }

    /**
     * Resolve the integrations from the user configuration with the container.
     *
     * @return SdkIntegration\IntegrationInterface[]
     */
    private function resolveIntegrationsFromUserConfig(array $userIntegrations, bool $enableDefaultTracingIntegrations): array
    {
        $integrationsToResolve = $userIntegrations;

        // sentry-laravel merges DEFAULT_INTEGRATIONS from Tracing\ServiceProvider here.
        // We have no default tracing integrations (LighthouseIntegration is not applicable).

        $integrations = [];

        foreach ($integrationsToResolve as $userIntegration) {
            if ($userIntegration instanceof SdkIntegration\IntegrationInterface) {
                $integrations[] = $userIntegration;
            } elseif (is_string($userIntegration)) {
                $resolvedIntegration = $this->app->make($userIntegration);

                if (! $resolvedIntegration instanceof SdkIntegration\IntegrationInterface) {
                    throw new RuntimeException(
                        sprintf(
                            'Sentry integrations must be an instance of `%s` got `%s`.',
                            SdkIntegration\IntegrationInterface::class,
                            $resolvedIntegration::class
                        )
                    );
                }

                $integrations[] = $resolvedIntegration;
            } else {
                throw new RuntimeException(
                    sprintf(
                        'Sentry integrations must either be a valid container reference or an instance of `%s`.',
                        SdkIntegration\IntegrationInterface::class
                    )
                );
            }
        }

        return $integrations;
    }

    /**
     * Check if a DSN was set in the config.
     */
    protected function hasDsnSet(): bool
    {
        $config = $this->getUserConfig();

        return ! empty($config['dsn']);
    }

    /**
     * Check if Spotlight was enabled in the config.
     */
    protected function hasSpotlightEnabled(): bool
    {
        $config = $this->getUserConfig();

        return ($config['spotlight'] ?? false) === true;
    }

    /**
     * Retrieve the user configuration.
     */
    protected function getUserConfig(): array
    {
        $config = $this->app['config'][static::$abstract];

        return empty($config) ? [] : $config;
    }
}
