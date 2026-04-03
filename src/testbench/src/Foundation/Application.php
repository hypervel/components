<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation;

use Closure;
use Hypervel\Console\Application as Artisan;
use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Foundation\Console\RouteListCommand;
use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Http\Middleware\TrustHosts;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Mail\Markdown;
use Hypervel\Queue\Console\WorkCommand;
use Hypervel\Queue\Queue;
use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Support\Arr;
use Hypervel\Support\EncodedHtmlString;
use Hypervel\Support\Once;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use Hypervel\Testbench\Bootstrap\RegisterProviders;
use Hypervel\Testbench\Concerns\CreatesApplication;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Bootstrap\EnsuresDefaultConfiguration;
use Hypervel\Testbench\Foundation\Bootstrap\LoadEnvironmentVariablesFromArray;
use Hypervel\Validation\Validator;
use Hypervel\View\Component;

class Application
{
    use CreatesApplication {
        createApplication as protected createApplicationFromTrait;
        resolveApplicationConfiguration as protected resolveApplicationConfigurationFromTrait;
    }

    /**
     * The Hypervel application instance.
     */
    protected ?ApplicationContract $app = null;

    /**
     * List of configurations.
     *
     * @var array{
     *   env: array<int, string>,
     *   providers: array<int, class-string>,
     *   dont-discover: array<int, string>,
     *   bootstrappers: null|array<int, class-string>|class-string
     * }
     */
    protected array $config = [
        'env' => [],
        'providers' => [],
        'dont-discover' => [],
        'bootstrappers' => [],
    ];

    /**
     * The application resolving callback.
     *
     * @var null|callable(ApplicationContract):void
     */
    protected $resolvingCallback;

    /**
     * Load environment variables from disk.
     */
    protected bool $loadEnvironmentVariables = false;

    /**
     * Create a new application resolver.
     *
     * @param null|callable(ApplicationContract):void $resolvingCallback
     */
    public function __construct(
        protected readonly ?string $basePath = null,
        ?callable $resolvingCallback = null
    ) {
        $this->resolvingCallback = $resolvingCallback;
    }

    /**
     * Create a new application resolver.
     *
     * @param null|callable(ApplicationContract):void $resolvingCallback
     * @param array<string, mixed> $options
     */
    public static function make(?string $basePath = null, ?callable $resolvingCallback = null, array $options = []): static
    {
        return (new static($basePath, $resolvingCallback))->configure($options);
    }

    /**
     * Create a new application resolver from configuration.
     *
     * @param null|callable(ApplicationContract):void $resolvingCallback
     * @param array<string, mixed> $options
     */
    public static function makeFromConfig(ConfigContract $config, ?callable $resolvingCallback = null, array $options = []): static
    {
        $basePath = $config['hypervel'] ?? static::applicationBasePath();

        return (new static($basePath, $resolvingCallback))->configure(array_merge($options, [
            'load_environment_variables' => is_file("{$basePath}/.env"),
            'extra' => $config->getExtraAttributes(),
        ]));
    }

    /**
     * Create a new application instance.
     *
     * @param null|callable(ApplicationContract):void $resolvingCallback
     * @param array<string, mixed> $options
     */
    public static function create(?string $basePath = null, ?callable $resolvingCallback = null, array $options = []): ApplicationContract
    {
        return static::make($basePath, $resolvingCallback, $options)->createApplication();
    }

    /**
     * Create a new application instance from configuration.
     *
     * @param null|callable(ApplicationContract):void $resolvingCallback
     * @param array<string, mixed> $options
     */
    public static function createFromConfig(ConfigContract $config, ?callable $resolvingCallback = null, array $options = []): ApplicationContract
    {
        return static::makeFromConfig($config, $resolvingCallback, $options)->createApplication();
    }

    /**
     * Create symlink to vendor path via new application instance.
     */
    public static function createVendorSymlink(?string $basePath, string $workingVendorPath): ApplicationContract
    {
        $app = static::create(basePath: $basePath, options: ['extra' => ['dont-discover' => ['*']]]);

        (new Actions\CreateVendorSymlink($workingVendorPath))->handle($app);

        return $app;
    }

    /**
     * Delete symlink to vendor path via new application instance.
     */
    public static function deleteVendorSymlink(?string $basePath): ApplicationContract
    {
        $app = static::create(basePath: $basePath, options: ['extra' => ['dont-discover' => ['*']]]);

        (new Actions\DeleteVendorSymlink())->handle($app);

        return $app;
    }

    /**
     * Flush the application state used by lightweight Testbench tests.
     */
    public static function flushState(object $instance): void
    {
        AboutCommand::flushState();
        Artisan::forgetBootstrappers();
        Component::flushCache();
        Component::forgetComponentsResolver();
        Component::forgetFactory();
        ConvertEmptyStringsToNull::flushState();
        EncodedHtmlString::flushState();
        Factory::flushState();
        HandleExceptions::flushState($instance instanceof \PHPUnit\Framework\TestCase ? $instance : null);
        Env::flushState();
        JsonResource::flushState();
        JsonApiResource::flushState();
        Markdown::flushState();
        Migrator::withoutMigrations([]);
        Model::automaticallyEagerLoadRelationships(false);
        Model::handleDiscardedAttributeViolationUsing(null);
        Model::handleLazyLoadingViolationUsing(null);
        Model::handleMissingAttributeViolationUsing(null);
        Model::preventAccessingMissingAttributes(false);
        Model::preventLazyLoading(false);
        Model::preventSilentlyDiscardingAttributes(false);
        Once::flushState();
        PreventRequestForgery::flushState();
        PreventRequestsDuringMaintenance::flushState();
        Queue::createPayloadUsing(null);
        RegisterProviders::flushState();
        RouteListCommand::resolveTerminalWidthUsing(null);
        ScheduleListCommand::resolveTerminalWidthUsing(null);
        SchemaBuilder::flushState();
        Sleep::flushState();
        Str::flushState();
        ThrottleRequests::shouldHashKeys();
        TrimStrings::flushState();
        TrustProxies::flushState();
        TrustHosts::flushState();
        Validator::flushState();
        WorkCommand::flushState();
    }

    /**
     * Configure the application options.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function configure(array $options): static
    {
        if (isset($options['load_environment_variables']) && \is_bool($options['load_environment_variables'])) {
            $this->loadEnvironmentVariables = $options['load_environment_variables'];
        }

        $config = Arr::only($options['extra'] ?? [], array_keys($this->config));

        /** @var array{
         *   env?: array<int, string>,
         *   providers?: array<int, class-string>,
         *   dont-discover?: array<int, string>,
         *   bootstrappers?: null|array<int, class-string>|class-string
         * } $config
         */
        $this->config = array_replace($this->config, $config);

        return $this;
    }

    /**
     * Determine if the container is running as a TestCase.
     */
    public function isRunningTestCase(): bool
    {
        return false;
    }

    /**
     * Ignore package discovery from.
     *
     * @return array<int, string>
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return $this->config['dont-discover'];
    }

    /**
     * Create the application instance.
     */
    public function createApplication(): ApplicationContract
    {
        $restoreEnvironment = $this->maskInheritedStandaloneEnvironment();

        try {
            $app = $this->createApplicationFromTrait();
        } finally {
            $restoreEnvironment();
        }

        $this->app = $app;

        if (\is_callable($this->resolvingCallback)) {
            \call_user_func($this->resolvingCallback, $app);
        }

        return $app;
    }

    /**
     * Mask inherited framework APP_ENV for standalone Testbench apps.
     *
     * The framework suite forces APP_ENV=testing globally via phpunit.xml.dist.
     * Standalone Testbench applications should instead use the workbench config
     * default unless the caller explicitly supplied APP_ENV or requested on-disk
     * environment loading.
     *
     * @return Closure(): void
     */
    protected function maskInheritedStandaloneEnvironment(): Closure
    {
        if (
            Env::has('TESTBENCH_PACKAGE_TESTER')
            || $this->hasConfiguredEnvironmentVariable('APP_ENV')
            || (Env::has('TESTBENCH_PACKAGE_REMOTE') && (isset($_SERVER['APP_ENV']) || isset($_ENV['APP_ENV'])))
        ) {
            return static fn (): null => null;
        }

        $originalServerValue = $_SERVER['APP_ENV'] ?? new UndefinedValue();
        $originalEnvironmentValue = $_ENV['APP_ENV'] ?? new UndefinedValue();
        $originalProcessValue = getenv('APP_ENV');

        unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);

        putenv('APP_ENV');
        Env::flushRepository();

        return static function () use ($originalServerValue, $originalEnvironmentValue, $originalProcessValue): void {
            if ($originalServerValue instanceof UndefinedValue) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $originalServerValue;
            }

            if ($originalEnvironmentValue instanceof UndefinedValue) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $originalEnvironmentValue;
            }

            if ($originalProcessValue === false) {
                putenv('APP_ENV');
            } else {
                putenv("APP_ENV={$originalProcessValue}");
            }

            Env::flushRepository();
        };
    }

    /**
     * Determine if an environment variable was provided via Testbench config.
     */
    protected function hasConfiguredEnvironmentVariable(string $key): bool
    {
        foreach ($this->config['env'] as $environmentVariable) {
            if (! is_string($environmentVariable)) { /* @phpstan-ignore function.alreadyNarrowedType */
                continue;
            }

            if (str_starts_with($environmentVariable, "{$key}=")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return $this->config['providers'];
    }

    /**
     * Get the package bootstrappers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageBootstrappers(ApplicationContract $app): array
    {
        $bootstrappers = $this->config['bootstrappers'] ?? null;

        if ($bootstrappers === null) {
            return [];
        }

        return Arr::wrap($bootstrappers);
    }

    /**
     * Resolve the application's base path.
     */
    protected function getApplicationBasePath(): string
    {
        return $this->basePath ?? static::applicationBasePath();
    }

    /**
     * Resolve application core environment variables implementation.
     */
    protected function resolveApplicationEnvironmentVariables(ApplicationContract $app): void
    {
        Env::disablePutenv();

        $app->terminating(static function (): void {
            Env::enablePutenv();
        });

        if ($this->loadEnvironmentVariables === true) {
            $app->make(LoadEnvironmentVariables::class)->bootstrap($app);
        }

        (new LoadEnvironmentVariablesFromArray($this->config['env']))->bootstrap($app);
    }

    /**
     * Load configuration and register package providers/aliases.
     */
    protected function resolveApplicationConfiguration(ApplicationContract $app): void
    {
        $this->resolveApplicationConfigurationFromTrait($app);
        (new EnsuresDefaultConfiguration())->bootstrap($app);
    }
}
