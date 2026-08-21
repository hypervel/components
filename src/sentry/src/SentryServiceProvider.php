<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Http\Kernel as HttpKernelInterface;
use Hypervel\Contracts\View\Engine;
use Hypervel\Contracts\View\View;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\PoolOptions;
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
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Integration as SdkIntegration;
use Sentry\Logger\DebugFileLogger;
use Sentry\Logs\Logs;
use Sentry\SentrySdk;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\State\HubInterface;
use Sentry\State\Layer;
use Throwable;

class SentryServiceProvider extends ServiceProvider
{
    /**
     * Configuration options that are Hypervel-specific and should not be sent to the base PHP SDK.
     */
    protected const array HYPERVEL_SPECIFIC_OPTIONS = [
        // These settings are Hypervel-specific and the PHP SDK will throw errors if it receives them
        'tracing',
        'breadcrumbs',
        'features',
        'pool',
        // We resolve the integrations through the container later, so we initially do not pass it to the SDK yet
        'integrations',
        // We have this setting to allow us to capture the .env LOG_LEVEL for the sentry_logs channel
        'logs_channel_level',
    ];

    /**
     * Options that should be resolved from the container instead of being passed directly to the SDK.
     */
    protected const array OPTIONS_TO_RESOLVE_FROM_CONTAINER = [
        'logger',
    ];

    /**
     * The abstract type to bind Sentry as in the service container.
     *
     * Boot-only. This value is read while registering container aliases and
     * config paths; runtime changes split later config reads from the
     * already-registered binding.
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
        if ($this->isActive()) {
            $this->bindEvents();
            $this->registerMiddleware();
            $this->bootTracing();
            $this->registerCoroutineContextPropagation();
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }

        $this->registerAboutCommandIntegration();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        if ($this->app->bound(SentryConfig::class)) {
            throw new LogicException(sprintf(
                'Sentry provider [%s] cannot be registered because another Sentry provider is already registered. Add [hypervel/sentry] to [extra.hypervel.dont-discover] before registering a custom provider, or remove the custom provider.',
                static::class,
            ));
        }

        $configRoot = static::$abstract;
        $this->app->singleton(
            SentryConfig::class,
            fn () => new SentryConfig($this->app->make(ConfigRepository::class), $configRoot),
        );

        $this->mergeConfigFrom(__DIR__ . '/../config/sentry.php', static::$abstract);

        $this->app->singleton(DebugFileLogger::class, function () {
            return new DebugFileLogger(storage_path('logs/sentry.log'));
        });

        $this->configureAndRegisterClient();

        $this->registerFeatures();

        $this->registerLogChannels();

        if ($this->isActive()) {
            $this->aspects(GuzzleHttpClientAspect::class);
        }
    }

    /**
     * Configure and register the Sentry client with the container.
     */
    protected function configureAndRegisterClient(): void
    {
        $configRoot = static::$abstract;

        // ClientBuilder — fresh per resolution so each Hub gets a properly configured builder
        $this->app->bind(ClientBuilder::class, function () use ($configRoot) {
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

            $clientBuilder->setSdkIdentifier(Version::getSdkIdentifier());
            $clientBuilder->setSdkVersion(Version::getSdkVersion());

            // Set the pooled transport for async sending via Swoole coroutines
            $poolConfig = $this->app->make('config')->array("{$configRoot}.pool");
            $transport = new HttpPoolTransport(
                new Pool(
                    $clientBuilder->getOptions(),
                    $this->sentryPoolOptions($poolConfig),
                )
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
            $userIntegrationOption = $userConfig['integrations'];

            $userIntegrations = $this->resolveIntegrationsFromUserConfig(
                is_array($userIntegrationOption) ? $userIntegrationOption : [],
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
                        // a Hypervel-specific request fetcher that reads from coroutine context.
                        if ($integration instanceof SdkIntegration\RequestIntegration) {
                            return false;
                        }

                        return true;
                    });

                    $integrations[] = new SdkIntegration\RequestIntegration(
                        new HypervelRequestFetcher
                    );
                }

                $integrations = array_merge(
                    $integrations,
                    [
                        new Integration,
                        new ContextIntegration,
                        new ExceptionContextIntegration,
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
     * Normalize the options supported by Sentry's standalone transport pool.
     */
    protected function sentryPoolOptions(array $config): PoolOptions
    {
        $supported = ['max_objects', 'wait_timeout', 'max_lifetime'];
        $unknown = array_diff(array_keys($config), $supported);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unsupported Sentry pool option(s) [' . implode(', ', $unknown) . ']. Supported options are ['
                . implode(', ', $supported) . '].'
            );
        }

        return PoolOptions::fromArray([
            ...$config,
            'max_idle_time' => 0,
            'idle_ttl' => null,
        ]);
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

            if ($userConfig['send_default_pii'] === true) {
                $handler->subscribeAuthEvents($dispatcher);
            }

            if ($userConfig['enable_logs'] === true) {
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

        // The second prepend makes Flush outermost, so its defer runs after tracing and feature finalizers.
        $httpKernel->prependMiddleware(TracingMiddleware::class);
        $httpKernel->prependMiddleware(FlushEventsMiddleware::class);

        if (SentrySdk::getCurrentHub()->getClient()?->getOptions()->shouldSendDefaultPii() === true) {
            $httpKernel->pushMiddleware(SetRequestIpMiddleware::class);
        }
    }

    /**
     * Boot tracing-related services (view engine, routing dispatchers, event handler).
     */
    protected function bootTracing(): void
    {
        $tracingConfig = $this->getUserConfig()['tracing'];

        // Register the tracing middleware as scoped so each coroutine gets its own instance.
        // Per-request state ($transaction, $appSpan, $didRouteMatch) is isolated between concurrent requests.
        $this->app->scoped(
            TracingMiddleware::class,
            static fn () => new TracingMiddleware(
                $tracingConfig['continue_after_response'] === true,
                $tracingConfig['missing_routes'] === true,
            ),
        );

        if (SentrySdk::getCurrentHub()->getClient()?->getOptions()->isTracingEnabled() !== true) {
            return;
        }

        $this->app->booted(function () {
            TracingMiddleware::setBootedTimestamp();
        });

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
        if ($tracingConfig['views'] !== true) {
            return;
        }

        $viewObserver = static function (ViewFactory $viewFactory): void {
            $viewFactory->observeRendering(static function (View $view): void {
                // The decorator reads this during the same render call; keep it
                // coroutine-local so concurrent renders do not swap view names.
                CoroutineContext::set(ViewEngineDecorator::CONTEXT_KEY, $view->name());
            });
        };

        if ($this->app->resolved('view')) {
            $viewObserver($this->app->make('view'));
        } else {
            $this->app->afterResolving('view', $viewObserver);
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
        return new ViewEngineDecorator($realEngine);
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
     * Copy isolated Sentry scope and request values into child coroutines.
     */
    protected function registerCoroutineContextPropagation(): void
    {
        /* @phpstan-ignore-next-line */
        Coroutine::afterCreated(function (): void {
            $parentId = Coroutine::parentId();
            $stack = CoroutineContext::get(Hub::CONTEXT_STACK_KEY)
                ?? CoroutineContext::get(Hub::CONTEXT_STACK_KEY, null, $parentId);

            if ($stack !== null) {
                CoroutineContext::set(
                    Hub::CONTEXT_STACK_KEY,
                    array_map(
                        static fn (Layer $layer): Layer => new Layer(
                            $layer->getClient(),
                            clone $layer->getScope(),
                        ),
                        $stack,
                    ),
                );
            }

            $request = CoroutineContext::get(Request::class)
                ?? CoroutineContext::get(Request::class, null, $parentId);

            if ($request !== null) {
                CoroutineContext::set(Request::class, clone $request);
            }
        });
    }

    /**
     * Register and bind all features.
     */
    protected function registerFeatures(): void
    {
        $features = $this->app->make('config')->array(static::$abstract . '.features');

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $featureInstance->register();
            } catch (Throwable $exception) {
                $this->reportFeatureFailure($feature, 'register', $exception);
            }
        }
    }

    /**
     * Boot all features.
     */
    protected function bootFeatures(): void
    {
        $bootActive = $this->isActive();

        $features = $this->app->make('config')->array(static::$abstract . '.features');

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $bootActive
                    ? $featureInstance->boot()
                    : $featureInstance->bootInactive();
            } catch (Throwable $exception) {
                $this->reportFeatureFailure(
                    $feature,
                    $bootActive ? 'boot' : 'bootInactive',
                    $exception,
                );
            }
        }
    }

    /**
     * Report a feature phase that did not complete.
     */
    private function reportFeatureFailure(string $feature, string $phase, Throwable $exception): void
    {
        $this->app->make(LoggerInterface::class)->warning(
            "Sentry feature [{$feature}] failed during [{$phase}]. The phase did not complete, any effects applied before the failure remain in place, and the phase will not be retried for this worker lifetime.",
            [
                'feature' => $feature,
                'phase' => $phase,
                'exception' => $exception,
            ],
        );
    }

    /**
     * Register the sentry and sentry_logs log channels.
     */
    protected function registerLogChannels(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $configRoot = static::$abstract;

        $logChannels = $config->array('logging.channels');

        if (! array_key_exists('sentry', $logChannels)) {
            $config->set('logging.channels.sentry', [
                'driver' => 'sentry',
            ]);
        }

        if (! array_key_exists('sentry_logs', $logChannels)) {
            $config->set('logging.channels.sentry_logs', [
                'driver' => 'sentry_logs',
                'level' => $config->string("{$configRoot}.logs_channel_level"),
            ]);
        }
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/sentry.php' => config_path(static::$abstract . '.php'),
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
    private function resolveIntegrationsFromUserConfig(array $userIntegrations): array
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
     * Determine if Sentry has an active endpoint.
     */
    protected function isActive(): bool
    {
        return $this->hasDsnSet() || $this->hasSpotlightEnabled();
    }

    /**
     * Check if a DSN was set in the config.
     */
    protected function hasDsnSet(): bool
    {
        return $this->app->make(SentryConfig::class)->hasDsnSet();
    }

    /**
     * Check if Spotlight was enabled in the config.
     */
    protected function hasSpotlightEnabled(): bool
    {
        return $this->app->make(SentryConfig::class)->hasSpotlightEnabled();
    }

    /**
     * Retrieve the user configuration.
     */
    protected function getUserConfig(): array
    {
        return $this->app->make(SentryConfig::class)->all();
    }
}
