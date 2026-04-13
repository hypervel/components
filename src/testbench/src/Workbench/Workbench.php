<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Workbench;

use Hypervel\Console\Application as Artisan;
use Hypervel\Console\Command;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Foundation\Events\DiagnosingHealth;
use Hypervel\Routing\Router;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\View;
use Hypervel\Support\Str;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

use function Hypervel\Testbench\after_resolving;
use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\testbench_path;
use function Hypervel\Testbench\workbench_path;

/**
 * @api
 *
 * @phpstan-import-type TWorkbenchDiscoversConfig from \Hypervel\Testbench\Foundation\Config
 */
class Workbench
{
    /**
     * The cached test case configuration.
     */
    protected static ?ConfigContract $cachedConfiguration = null;

    /**
     * Cached namespace by path.
     *
     * @var array<string, null|string>
     */
    protected static array $cachedNamespaces = [];

    /**
     * The cached test case configuration.
     *
     * @var null|class-string<AuthenticatableContract>|false
     */
    protected static string|false|null $cachedUserModel = null;

    /**
     * The cached core workbench bindings.
     *
     * @var array{kernel: array{console?: null|string, http?: null|string}, handler: array{exception?: null|string}}
     */
    public static array $cachedCoreBindings = [
        'kernel' => [],
        'handler' => [],
    ];

    /**
     * Start Workbench.
     *
     * @internal
     *
     * @codeCoverageIgnore
     */
    public static function start(ApplicationContract $app, ConfigContract $config, array $providers = []): void
    {
        $app->singleton(ConfigContract::class, static fn (): ConfigContract => $config);

        (new Collection($providers))
            ->filter(static fn ($provider) => ! empty($provider) && class_exists($provider))
            ->each(static function ($provider) use ($app) {
                $app->register($provider);
            });
    }

    /**
     * Start Workbench with providers.
     *
     * @internal
     *
     * @codeCoverageIgnore
     */
    public static function startWithProviders(ApplicationContract $app, ConfigContract $config): void
    {
        $providers = $config->getExtraAttributes()['providers'];

        if ($config->getWorkbenchAttributes()['auth'] === true
            && class_exists(\Hypervel\Auth\AuthServiceProvider::class)
            && ! in_array(\Hypervel\Auth\AuthServiceProvider::class, $providers, true)) {
            $providers[] = \Hypervel\Auth\AuthServiceProvider::class;
        }

        static::start($app, $config, $providers);
    }

    /**
     * Discover Workbench routes.
     */
    public static function discoverRoutes(ApplicationContract $app, ConfigContract $config): void
    {
        /** @var TWorkbenchDiscoversConfig $discoversConfig */
        $discoversConfig = $config->getWorkbenchDiscoversAttributes();

        $healthCheckEnabled = $config->getWorkbenchAttributes()['health'] ?? false;

        $app->booted(static function ($app) use ($discoversConfig, $healthCheckEnabled) {
            tap($app->make('router'), static function (Router $router) use ($discoversConfig, $healthCheckEnabled) {
                if ($discoversConfig['api'] === true) {
                    if (is_file($route = workbench_path('routes', 'api.php'))) {
                        $router->middleware('api')->group($route);
                    }
                }

                if ($healthCheckEnabled === true) {
                    $router->get('/up', static function () {
                        $exception = null;

                        try {
                            Event::dispatch(new DiagnosingHealth);
                        } catch (Throwable $error) {
                            if (app()->hasDebugModeEnabled()) {
                                throw $error;
                            }

                            report($error);

                            $exception = $error->getMessage();
                        }

                        return response(
                            View::file(
                                dirname(__DIR__, 3) . '/foundation/src/resources/health-up.blade.php',
                                ['exception' => $exception],
                            ),
                            status: $exception ? 500 : 200,
                        );
                    });
                }

                if ($discoversConfig['web'] === true) {
                    if (is_file($route = workbench_path('routes', 'web.php'))) {
                        $router->middleware('web')->group($route);
                    }
                }
            });

            if ($app->runningInConsole() && $discoversConfig['commands'] === true) {
                static::discoverCommandsRoutes($app);
            }
        });

        after_resolving($app, 'translator', static function ($translator) {
            $path = (new Collection([
                workbench_path('lang'),
                workbench_path('resources', 'lang'),
            ]))->filter(static fn ($path) => is_dir($path))
                ->first();

            if (\is_null($path)) {
                return;
            }

            $translator->addNamespace('workbench', $path);
        });

        if (is_dir($workbenchViewPath = workbench_path('resources', 'views'))) {
            if ($discoversConfig['views'] === true) {
                $app->booted(static function () use ($app, $workbenchViewPath) {
                    tap($app->make('config'), function ($config) use ($workbenchViewPath) {
                        $config->set('view.paths', array_merge(
                            $config->get('view.paths', []),
                            [$workbenchViewPath]
                        ));
                    });
                });
            }

            after_resolving($app, 'view', static function ($view, $app) use ($discoversConfig, $workbenchViewPath) {
                if ($discoversConfig['views'] === true && method_exists($view, 'addLocation')) {
                    $view->addLocation($workbenchViewPath);
                }

                $view->addNamespace('workbench', $workbenchViewPath);
            });
        }

        after_resolving($app, 'blade.compiler', static function ($blade) use ($discoversConfig) {
            if ($discoversConfig['components'] === false && is_dir(workbench_path('app', 'View', 'Components'))) {
                $blade->componentNamespace('Workbench\App\View\Components', 'workbench');
            }
        });

        if ($discoversConfig['factories'] === true) {
            Factory::guessFactoryNamesUsing(static function (string $modelName): string {
                $workbenchNamespace = static::detectNamespace('app') ?? 'Workbench\App\\';
                $factoryNamespace = static::detectNamespace('database/factories') ?? 'Workbench\Database\Factories\\';

                $modelBasename = str_starts_with($modelName, $workbenchNamespace . 'Models\\')
                    ? Str::after($modelName, $workbenchNamespace . 'Models\\')
                    : Str::after($modelName, $workbenchNamespace);

                $factoryName = $factoryNamespace . $modelBasename . 'Factory';

                return $factoryName;
            });

            Factory::guessModelNamesUsing(static function (Factory $factory): string {
                $workbenchNamespace = static::detectNamespace('app') ?? 'Workbench\App\\';
                $factoryNamespace = static::detectNamespace('database/factories') ?? 'Workbench\Database\Factories\\';

                $namespacedFactoryBasename = Str::replaceLast(
                    'Factory',
                    '',
                    Str::replaceFirst($factoryNamespace, '', $factory::class)
                );

                $factoryBasename = Str::replaceLast('Factory', '', class_basename($factory));

                $modelName = class_exists($workbenchNamespace . 'Models\\' . $namespacedFactoryBasename)
                    ? $workbenchNamespace . 'Models\\' . $namespacedFactoryBasename
                    : $workbenchNamespace . $factoryBasename;

                return $modelName;
            });
        }
    }

    /**
     * Discover Workbench command routes.
     */
    public static function discoverCommandsRoutes(ApplicationContract $app): void
    {
        if (is_file($console = workbench_path('routes', 'console.php'))) {
            require $console;
        }

        if (! is_dir(workbench_path('app', 'Console', 'Commands'))) {
            return;
        }

        $namespace = rtrim(static::detectNamespace('app') ?? 'Workbench\App\\', '\\');

        foreach ((new Finder)->in([workbench_path('app', 'Console', 'Commands')])->files() as $command) {
            $command = $namespace . str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($command->getRealPath(), (string) realpath(workbench_path('app') . DIRECTORY_SEPARATOR))
            );

            if (
                is_subclass_of($command, Command::class)
                && ! (new ReflectionClass($command))->isAbstract()
            ) {
                Artisan::starting(static function ($artisan) use ($command) {
                    $artisan->resolve($command);
                });
            }
        }
    }

    /**
     * Resolve the configuration.
     * @codeCoverageIgnore
     */
    public static function configuration(): ConfigContract
    {
        return static::$cachedConfiguration ??= Bootstrapper::getConfiguration()
            ?? Config::cacheFromYaml(
                is_file(package_path('testbench.yaml'))
                || is_file(package_path('testbench.yaml.example'))
                || is_file(package_path('testbench.yaml.dist'))
                    ? package_path()
                    : testbench_path()
            );
    }

    /**
     * Get application Console Kernel implementation.
     */
    public static function applicationConsoleKernel(): ?string
    {
        if (! isset(static::$cachedCoreBindings['kernel']['console'])) {
            static::$cachedCoreBindings['kernel']['console'] = is_file(workbench_path('app', 'Console', 'Kernel.php'))
                ? \sprintf('%sConsole\Kernel', static::detectNamespace('app'))
                : null;
        }

        return static::$cachedCoreBindings['kernel']['console'];
    }

    /**
     * Get application HTTP Kernel implementation using Workbench.
     */
    public static function applicationHttpKernel(): ?string
    {
        if (! isset(static::$cachedCoreBindings['kernel']['http'])) {
            static::$cachedCoreBindings['kernel']['http'] = is_file(workbench_path('app', 'Http', 'Kernel.php'))
                ? \sprintf('%sHttp\Kernel', static::detectNamespace('app'))
                : null;
        }

        return static::$cachedCoreBindings['kernel']['http'];
    }

    /**
     * Get application HTTP exception handler using Workbench.
     */
    public static function applicationExceptionHandler(): ?string
    {
        if (! isset(static::$cachedCoreBindings['handler']['exception'])) {
            static::$cachedCoreBindings['handler']['exception'] = is_file(workbench_path('app', 'Exceptions', 'ExceptionHandler.php'))
                ? \sprintf('%sExceptions\ExceptionHandler', static::detectNamespace('app'))
                : null;
        }

        return static::$cachedCoreBindings['handler']['exception'];
    }

    /**
     * Get application User Model.
     *
     * @return null|class-string<AuthenticatableContract>
     */
    public static function applicationUserModel(): ?string
    {
        if (\is_null(static::$cachedUserModel)) {
            $authModel = Env::get('AUTH_MODEL');

            /** @var class-string<AuthenticatableContract>|false $userModel */
            $userModel = match (true) {
                is_string($authModel) && $authModel !== '' => $authModel,
                is_file(workbench_path('app', 'Models', 'User.php')) => \sprintf('%sModels\User', static::detectNamespace('app')),
                is_file(base_path(join_paths('Models', 'User.php'))) => 'App\Models\User',
                default => false,
            };

            static::$cachedUserModel = $userModel;
        }

        return static::$cachedUserModel !== false ? static::$cachedUserModel : null;
    }

    /**
     * Detect namespace by type.
     */
    public static function detectNamespace(string $type, bool $force = false): ?string
    {
        $type = trim($type, '/');

        if (! isset(static::$cachedNamespaces[$type]) || $force === true) {
            static::$cachedNamespaces[$type] = null;

            /** @var array{'autoload': array{'psr-4'?: array<string, array<int, string>|string>}, 'autoload-dev': array{'psr-4'?: array<string, array<int, string>|string>}} $composer */
            $composer = json_decode((string) file_get_contents(package_path('composer.json')), true);

            $collection = array_merge(
                $composer['autoload']['psr-4'] ?? [],
                $composer['autoload-dev']['psr-4'] ?? [],
            );

            $path = implode('/', ['workbench', $type]);

            foreach ((array) $collection as $namespace => $paths) {
                foreach ((array) $paths as $pathChoice) {
                    if (trim($pathChoice, '/') === $path) {
                        static::$cachedNamespaces[$type] = $namespace;
                    }
                }
            }
        }

        $defaults = [
            'app' => 'Workbench\App\\',
            'database/factories' => 'Workbench\Database\Factories\\',
            'database/seeders' => 'Workbench\Database\Seeders\\',
        ];

        return static::$cachedNamespaces[$type] ?? $defaults[$type] ?? null;
    }

    /**
     * Flush the cached configuration.
     *
     * @codeCoverageIgnore
     */
    public static function flush(): void
    {
        static::$cachedConfiguration = null;

        static::flushCachedClassAndNamespaces();
    }

    /**
     * Flush the cached namespace configuration.
     *
     * @codeCoverageIgnore
     */
    public static function flushCachedClassAndNamespaces(): void
    {
        static::$cachedUserModel = null;
        static::$cachedNamespaces = [];

        static::$cachedCoreBindings = [
            'kernel' => [],
            'handler' => [],
        ];
    }
}
