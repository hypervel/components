<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use ArrayObject;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Http\Kernel as HttpKernelInterface;
use Hypervel\Contracts\View\Engine;
use Hypervel\Contracts\View\View;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Routing\Contracts\CallableDispatcher;
use Hypervel\Routing\Contracts\ControllerDispatcher;
use Hypervel\Sentry\Console\AboutCommandIntegration;
use Hypervel\Sentry\Console\PublishCommand;
use Hypervel\Sentry\Console\TestCommand;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\Http\FlushEventsMiddleware;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Sentry\Http\SetRequestIpMiddleware;
use Hypervel\Sentry\Integration\ExceptionContextIntegration;
use Hypervel\Sentry\Integration\HypervelContextIntegration;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Hypervel\Sentry\Tracing\BacktraceHelper;
use Hypervel\Sentry\Tracing\EventHandler as TracingEventHandler;
use Hypervel\Sentry\Tracing\Middleware as TracingMiddleware;
use Hypervel\Sentry\Tracing\Routing\TracingCallableDispatcherTracing;
use Hypervel\Sentry\Tracing\Routing\TracingControllerDispatcherTracing;
use Hypervel\Sentry\Tracing\ViewEngineDecorator;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\HttpTransportPool;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Support\ServiceProvider;
use Hypervel\View\Engines\EngineResolver;
use Hypervel\View\Factory as ViewFactory;
use Hypervel\WebSocketServer\Events\ConnectionClosing;
use Hypervel\WebSocketServer\Events\ConnectionOpening;
use Hypervel\WebSocketServer\Events\MessageReceived;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Integration as SdkIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Logger\DebugFileLogger;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\State\HubInterface;
use Sentry\State\Layer;
use Sentry\State\Scope;
use Throwable;

class SentryServiceProvider extends ServiceProvider
{
    /**
     * List of configuration options that are Hypervel specific and should not be sent to the base PHP SDK.
     */
    protected const array HYPERVEL_SPECIFIC_OPTIONS = [
        // We do not want these settings to hit the PHP SDK because they are Hypervel specific and the PHP SDK will throw errors
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
     * List of options that should be resolved from the container instead of being passed directly to the SDK.
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
     * Indicates if this process is a server process that may still fork workers and processes.
     */
    protected bool $serverMayFork = false;

    /**
     * The client options whose connection sharing is suspended until a forked worker or process starts.
     *
     * @var list<Options>
     */
    protected array $optionsAwaitingFork = [];

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        if ($this->serverMayFork) {
            $this->app->make('events')->listen([BeforeWorkerStart::class, BeforeProcessHandle::class], function (): void {
                $this->serverMayFork = false;

                foreach ($this->optionsAwaitingFork as $options) {
                    $options->setEnableShareHandle(true);
                }

                $this->optionsAwaitingFork = [];
            });
        }

        $active = $this->isActive();
        $runtimeContextStorage = $active
            ? $this->app->make(CoroutineRuntimeContextStorage::class)
            : null;

        SentrySdk::setRuntimeContextStorage($runtimeContextStorage);

        // Eagerly resolve the Hub so SentrySdk has it available globally
        $this->app->make(HubInterface::class);

        if ($runtimeContextStorage !== null) {
            $this->registerRuntimeContextBoundaries();
            $this->registerCoroutineContextPropagation($runtimeContextStorage);

            $this->app->terminating(static function () use ($runtimeContextStorage): void {
                if ($runtimeContextStorage->get() === null) {
                    SentrySdk::flush();
                }
            });
        }

        $this->bootFeatures($active);

        // Only register event/middleware/tracing if a DSN is set or Spotlight is enabled.
        // No events can be sent without a DSN or Spotlight.
        if ($active) {
            $this->bindEvents();
            $this->registerMiddleware();
            $this->bootTracing();
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
            fn (): SentryConfig => new SentryConfig($this->app->make(ConfigRepository::class), $configRoot),
        );

        $this->mergeConfigFrom(__DIR__ . '/../config/sentry.php', static::$abstract);

        $this->app->singleton(DebugFileLogger::class, function (): DebugFileLogger {
            return new DebugFileLogger(storage_path('logs/sentry.log'));
        });

        $this->configureAndRegisterClient();

        $this->registerFeatures();

        $this->registerLogChannels();
    }

    /**
     * Configure and register the Sentry client with the container.
     */
    protected function configureAndRegisterClient(): void
    {
        $configRoot = static::$abstract;

        // ClientBuilder — fresh per resolution so each Hub gets a properly configured builder
        $this->app->bind(ClientBuilder::class, function () use ($configRoot): ClientBuilder {
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

            // When we get no environment from the (user) configuration we default to the Hypervel environment
            if (empty($options['environment'])) {
                $options['environment'] = $this->app->environment();
            }

            foreach (self::OPTIONS_TO_RESOLVE_FROM_CONTAINER as $option) {
                if (isset($options[$option]) && is_string($options[$option])) {
                    $options[$option] = $this->app->make($options[$option]);
                }
            }

            $clientBuilder = ClientBuilder::create($options);

            $this->classifyProxyFiles($clientBuilder->getOptions());

            // Set the Hypervel SDK identifier and version
            $clientBuilder->setSdkIdentifier(Version::SDK_IDENTIFIER);
            $clientBuilder->setSdkVersion(Version::getSdkVersion());

            // Make the pooled transport the default transport option for async sending via Swoole coroutines,
            // so a transport configured in the options or set on the builder takes precedence as in the SDK
            $sentryOptions = $clientBuilder->getOptions();

            if ($sentryOptions->getTransport() === null) {
                $poolConfig = $this->app->make('config')->array("{$configRoot}.pool");

                $sentryOptions->setTransport(new HttpPoolTransport(
                    new HttpTransportPool($clientBuilder, $this->sentryPoolOptions($poolConfig)),
                    $sentryOptions,
                ));
            }

            return $clientBuilder;
        });

        // The server process boots the application, then forks every worker and process. Connections it
        // kept in the SDK's cURL share handle would be reused by all of them at once, so it sends without
        // sharing until a forked process restores the setting. This runs after extenders, which may choose it.
        $this->serverMayFork = ! $this->app->runningInConsole();

        if ($this->serverMayFork) {
            $this->app->afterResolving(ClientBuilder::class, function (ClientBuilder $clientBuilder): void {
                $options = $clientBuilder->getOptions();

                if ($this->serverMayFork && $options->isShareHandleEnabled()) {
                    $options->setEnableShareHandle(false);
                    $this->optionsAwaitingFork[] = $options;
                }
            });
        }

        // HubInterface singleton — coroutine-scoped hub with full integration setup
        $this->app->singleton(HubInterface::class, function (): Hub {
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
                    // Remove the default error and fatal exception listeners to let Hypervel handle those
                    // itself. These events still bubble up through the documented changes in the user's
                    // `ExceptionHandler` of their application or through the log channel integration to Sentry
                    $integrations = array_filter($integrations, static function (IntegrationInterface $integration): bool {
                        if ($integration instanceof SdkIntegration\ErrorListenerIntegration) {
                            return false;
                        }

                        if ($integration instanceof SdkIntegration\ExceptionListenerIntegration) {
                            return false;
                        }

                        if ($integration instanceof SdkIntegration\FatalErrorListenerIntegration) {
                            return false;
                        }

                        // We also remove the default request integration so it can be re-added
                        // after with a Hypervel specific request fetcher. This way we can resolve
                        // the request from Hypervel instead of constructing it from the global state
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
                        new HypervelContextIntegration,
                        new ExceptionContextIntegration,
                    ],
                    $userIntegrations
                );

                if (is_callable($userIntegrationOption)) {
                    return $userIntegrationOption($integrations);
                }

                return $integrations;
            });

            // Exception handler configuration through withExceptions(..) might run before the real Sentry hub
            // is instantiated. By copying the scope here we can preserve data that was set on the Scope before
            // this function was executed.
            $hub = new Hub($clientBuilder->getClient(), $this->cloneCurrentHubScope());

            SentrySdk::setCurrentHub($hub);

            return $hub;
        });

        $this->app->alias(HubInterface::class, static::$abstract);

        $this->app->singleton(BacktraceHelper::class, function (): BacktraceHelper {
            $sentry = $this->app->make(HubInterface::class);

            $options = $sentry->getClient()->getOptions();

            return new BacktraceHelper($options, new RepresentationSerializer($options));
        });
    }

    /**
     * Clone the scope configured before the Sentry client was resolved.
     */
    private function cloneCurrentHubScope(): ?Scope
    {
        $currentHub = SentrySdk::getCurrentHub();

        if ($currentHub->getClient() !== null) {
            return null;
        }

        $clonedScope = null;

        $currentHub->configureScope(static function (Scope $scope) use (&$clonedScope): void {
            $clonedScope = clone $scope;
        });

        return $clonedScope;
    }

    /**
     * Classify generated AOP proxy files like the source files they replace.
     *
     * Proxies are generated under the bootstrap cache path, so a proxied vendor class would
     * otherwise count as application code in stack traces and span origins. An explicit include
     * that covers a proxy still wins, because the SDK applies includes over excludes.
     */
    private function classifyProxyFiles(Options $options): void
    {
        $excludedPaths = $options->getInAppExcludedPaths();
        $includedPaths = $options->getInAppIncludedPaths();
        $proxyExclusions = $proxyInclusions = [];

        foreach (GenerateProxies::proxySources() as $proxyFile => $sourceFile) {
            $sourceIsInApp = $this->isInAppFile($sourceFile, $excludedPaths, $includedPaths);

            if ($sourceIsInApp === $this->isInAppFile($proxyFile, $excludedPaths, $includedPaths)) {
                continue;
            }

            if ($sourceIsInApp) {
                $proxyInclusions[] = $proxyFile;
            } else {
                $proxyExclusions[] = $proxyFile;
            }
        }

        if ($proxyExclusions !== []) {
            $options->setInAppExcludedPaths([...$excludedPaths, ...$proxyExclusions]);
        }

        if ($proxyInclusions !== []) {
            $options->setInAppIncludedPaths([...$includedPaths, ...$proxyInclusions]);
        }
    }

    /**
     * Determine if the SDK treats the given canonical file path as application code.
     *
     * Mirrors the path rules of the SDK's FrameBuilder::isFrameInApp().
     *
     * @param string[] $excludedPaths
     * @param string[] $includedPaths
     */
    private function isInAppFile(string $file, array $excludedPaths, array $includedPaths): bool
    {
        foreach ($includedPaths as $includedPath) {
            if (str_starts_with($file, $includedPath)) {
                return true;
            }
        }

        foreach ($excludedPaths as $excludedPath) {
            if (str_starts_with($file, $excludedPath)) {
                return false;
            }
        }

        return true;
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
            'max_idle_time' => null,
            'pool_idle_timeout' => null,
        ]);
    }

    /**
     * Bind to the event dispatcher to log events.
     */
    protected function bindEvents(): void
    {
        $userConfig = $this->getUserConfig();

        $handler = new EventHandler($this->app, $userConfig);

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');

        $handler->subscribe($dispatcher);

        if (DataCollectionPolicy::fromHub($this->app->make(HubInterface::class))->shouldCollectUserInfo()) {
            $handler->subscribeAuthEvents($dispatcher);
        }
    }

    /**
     * Start runtime contexts before execution-specific Sentry listeners run.
     */
    protected function registerRuntimeContextBoundaries(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');
        $listener = function (): void {
            $this->app->make(RuntimeContextBoundary::class)->start();
        };

        foreach ([
            JobProcessing::class,
            ScheduledTaskStarting::class,
            ConnectionOpening::class,
            MessageReceived::class,
            ConnectionClosing::class,
        ] as $event) {
            $dispatcher->listen($event, $listener);
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

        // The second prepend makes the runtime context outermost, so it ends after tracing and feature finalizers.
        $httpKernel->prependMiddleware(TracingMiddleware::class);
        $httpKernel->prependMiddleware(FlushEventsMiddleware::class);

        if (DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub())->shouldCollectUserInfo()) {
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
            static fn (): TracingMiddleware => new TracingMiddleware(
                $tracingConfig['continue_after_response'] === true,
                $tracingConfig['missing_routes'] === true,
            ),
        );

        if (SentrySdk::getCurrentHub()->getClient()?->getOptions()->isTracingEnabled() !== true) {
            return;
        }

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

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');

        $handler->subscribe($dispatcher);
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
                $realEngine = $engineResolver->resolve($engineName);

                // Prevent double wrapping, which would record every view render twice
                if ($realEngine instanceof ViewEngineDecorator) {
                    continue;
                }

                $engineResolver->register($engineName, function () use ($realEngine): Engine {
                    return $this->wrapViewEngine($realEngine);
                });
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
        $this->app->extend(CallableDispatcher::class, static function (CallableDispatcher $dispatcher): TracingCallableDispatcherTracing {
            return new TracingCallableDispatcherTracing($dispatcher);
        });

        $this->app->extend(ControllerDispatcher::class, static function (ControllerDispatcher $dispatcher): TracingControllerDispatcherTracing {
            return new TracingControllerDispatcherTracing($dispatcher);
        });
    }

    /**
     * Register the coroutine context propagation hook.
     *
     * Copy the Sentry scope stack into child coroutines and pass them the request for Sentry.
     */
    protected function registerCoroutineContextPropagation(
        CoroutineRuntimeContextStorage $runtimeContextStorage,
    ): void {
        Coroutine::afterCreated(static function () use ($runtimeContextStorage): void {
            /** @var ArrayObject<string, mixed> $context */
            $context = CoroutineContext::getContainer();

            if (isset($context[HttpPoolTransport::DELIVERY_CONTEXT_KEY])) {
                return;
            }

            /** @var null|ArrayObject<string, mixed> $parentContext */
            $parentContext = isset($context[Coroutine::DETACHED_CONTEXT_KEY])
                ? null
                : CoroutineContext::getContainer(Coroutine::parentId());

            /** @var null|list<Layer> $stack */
            $stack = $context[Hub::CONTEXT_STACK_KEY]
                ?? $parentContext[Hub::CONTEXT_STACK_KEY]
                ?? null;

            if ($stack !== null) {
                $context[Hub::CONTEXT_STACK_KEY] = array_map(
                    static fn (Layer $layer): Layer => new Layer(
                        $layer->getClient(),
                        clone $layer->getScope(),
                    ),
                    $stack,
                );
            }

            /** @var ?AiTracingScope $parentAiScope */
            $parentAiScope = $parentContext[AiTracingScope::CONTEXT_KEY] ?? null;

            if ($parentAiScope !== null) {
                $context[AiTracingScope::CONTEXT_KEY] = new AiTracingScope(
                    $parentAiScope->state,
                    $parentAiScope->conversation,
                );
            }

            // Events in the child still carry the request, without Sentry changing the
            // application request context the child sees.
            if (! isset($context[HypervelRequestFetcher::REQUEST_CONTEXT_KEY])) {
                /** @var ?Request $request */
                $request = $parentContext[Request::class]
                    ?? $parentContext[HypervelRequestFetcher::REQUEST_CONTEXT_KEY]
                    ?? null;

                if ($request !== null) {
                    $context[HypervelRequestFetcher::REQUEST_CONTEXT_KEY] = $request;
                }
            }

            if ($runtimeContextStorage->inheritFrom($context, $parentContext)) {
                Coroutine::defer(static function (): void {
                    SentrySdk::endContext();
                });
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
                // Ensure that features do not break the whole application
                $this->reportFeatureFailure($feature, 'register', $exception);
            }
        }
    }

    /**
     * Boot all features.
     */
    protected function bootFeatures(bool $active): void
    {
        $features = $this->app->make('config')->array(static::$abstract . '.features');

        foreach ($features as $feature) {
            try {
                /** @var Feature $featureInstance */
                $featureInstance = $this->app->make($feature);

                $active
                    ? $featureInstance->boot()
                    : $featureInstance->bootInactive();
            } catch (Throwable $exception) {
                // Ensure that features do not break the whole application
                $this->reportFeatureFailure(
                    $feature,
                    $active ? 'boot' : 'bootInactive',
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
        $configRoot = static::$abstract;

        $this->configureUsing(static function (Repository $config) use ($configRoot): void {
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
        });
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
            TestCommand::class,
            PublishCommand::class,
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
     * @return IntegrationInterface[]
     */
    private function resolveIntegrationsFromUserConfig(array $userIntegrations): array
    {
        $integrationsToResolve = $userIntegrations;

        // sentry-laravel merges DEFAULT_INTEGRATIONS from Tracing\ServiceProvider here.
        // We have no default tracing integrations (LighthouseIntegration is not applicable).

        $integrations = [];

        foreach ($integrationsToResolve as $userIntegration) {
            if ($userIntegration instanceof IntegrationInterface) {
                $integrations[] = $userIntegration;
            } elseif (is_string($userIntegration)) {
                $resolvedIntegration = $this->app->make($userIntegration);

                if (! $resolvedIntegration instanceof IntegrationInterface) {
                    throw new RuntimeException(
                        sprintf(
                            'Sentry integrations must be an instance of `%s` got `%s`.',
                            IntegrationInterface::class,
                            $resolvedIntegration::class
                        )
                    );
                }

                $integrations[] = $resolvedIntegration;
            } else {
                throw new RuntimeException(
                    sprintf(
                        'Sentry integrations must either be a valid container reference or an instance of `%s`.',
                        IntegrationInterface::class
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
