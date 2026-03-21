<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Console;

use Closure;
use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application as HypervelApplication;
use Hypervel\Testbench\Foundation\Application as Testbench;
use Hypervel\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\Concerns\CopyTestbenchFiles;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Testbench\Workbench\Workbench;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\transform_relative_path;

class Commander
{
    use CopyTestbenchFiles;

    /**
     * Application instance.
     */
    protected ?ApplicationContract $app = null;

    /**
     * List of configurations.
     */
    protected readonly Config $config;

    /**
     * The environment file name.
     */
    protected string $environmentFile = '.env';

    /**
     * The testbench implementation class.
     *
     * @var class-string<Testbench>
     */
    protected static string $testbench = Testbench::class;

    /**
     * List of providers.
     *
     * @var array<int, class-string>
     */
    protected array $providers = [
        TestbenchServiceProvider::class,
    ];

    /**
     * Registered pcntl signal handlers for cleanup on teardown.
     *
     * @var array<int, int>
     */
    protected array $registeredSignals = [];

    /**
     * Whether async signals were enabled before we changed them.
     */
    protected ?bool $previousAsyncSignals = null;

    /**
     * Construct a new Commander.
     */
    public function __construct(
        Config|array $config,
        protected readonly string $workingPath
    ) {
        $this->config = $config instanceof Config ? $config : new Config($config);

        $_ENV['TESTBENCH_ENVIRONMENT_FILENAME'] = $this->environmentFile;
    }

    /**
     * Handle the command.
     */
    public function handle(): void
    {
        $input = new ArgvInput();
        $output = new ConsoleOutput();

        try {
            $hypervel = $this->hypervel();
            $kernel = $hypervel->make(ConsoleKernel::class);

            $this->prepareCommandSignals();

            $status = $kernel->handle($input, $output);

            $kernel->terminate($input, $status);
        } catch (Throwable $error) {
            $status = $this->handleException($output, $error);
        } finally {
            TerminatingConsole::handle();
            Workbench::flush();

            $this->unregisterSignals();
        }

        exit($status);
    }

    /**
     * Create a Hypervel application.
     */
    public function hypervel(): ApplicationContract
    {
        if (! $this->app instanceof HypervelApplication) {
            $appBasePath = $this->getApplicationBasePath();
            $vendorPath = package_path('vendor');

            TerminatingConsole::beforeWhen(
                ! is_symlink(join_paths($appBasePath, 'vendor')),
                static function () use ($appBasePath) {
                    static::$testbench::deleteVendorSymlink($appBasePath);
                }
            );

            $filesystem = new Filesystem();

            $hasEnvironmentFile = static fn () => is_file(join_paths($appBasePath, '.env'));

            tap(
                static::$testbench::createVendorSymlink($appBasePath, $vendorPath),
                function ($app) use ($filesystem, $hasEnvironmentFile) {
                    $this->copyTestbenchConfigurationFile($app, $filesystem, $this->workingPath);

                    if (! $hasEnvironmentFile()) {
                        $this->copyTestbenchDotEnvFile($app, $filesystem, $this->workingPath);
                    }
                }
            );

            $this->app = static::$testbench::create(
                basePath: $appBasePath,
                resolvingCallback: $this->resolveApplicationCallback(),
                options: array_filter([
                    'load_environment_variables' => $hasEnvironmentFile(),
                    'extra' => $this->config->getExtraAttributes(),
                ]),
            );

            $this->app->instance('TESTBENCH_COMMANDER', $this);
        }

        return $this->app;
    }

    /**
     * Resolve application implementation callback.
     *
     * @return Closure(ApplicationContract):void
     */
    protected function resolveApplicationCallback(): Closure
    {
        return function ($app) {
            Workbench::startWithProviders($app, $this->config);
            Workbench::discoverRoutes($app, $this->config);

            (new LoadMigrationsFromArray(
                $this->config['migrations'] ?? [],
                $this->config['seeders'] ?? false,
            ))->bootstrap($app);

            foreach ($this->providers as $provider) {
                $app->register($provider);
            }
        };
    }

    /**
     * Resolve the application's base path.
     */
    protected function getApplicationBasePath(): string
    {
        $path = $this->config['hypervel'] ?? null;

        if ($path !== null && ! isset($_ENV['APP_BASE_PATH'])) {
            $resolved = transform_relative_path($path, $this->workingPath) ?? $path;
            $_ENV['APP_BASE_PATH'] = $resolved;

            return $resolved;
        }

        return static::applicationBasePath();
    }

    /**
     * Get the application's base path.
     */
    public static function applicationBasePath(): string
    {
        return static::$testbench::applicationBasePath();
    }

    /**
     * Render an exception to the console.
     */
    protected function handleException(OutputInterface $output, Throwable $error): int
    {
        if ($this->app instanceof HypervelApplication) {
            tap($this->app->make(ExceptionHandler::class), static function ($handler) use ($error, $output) {
                $handler->report($error);
                $handler->renderForConsole($output, $error);
            });
        } else {
            (new ConsoleApplication())->renderThrowable($error, $output);
        }

        return 1;
    }

    /**
     * Prepare process-level signal handlers for clean shutdown.
     *
     * Uses pcntl directly since Commander runs outside the Swoole event loop.
     */
    protected function prepareCommandSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $this->previousAsyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);

        $signals = [SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2, SIGQUIT];

        foreach ($signals as $signal) {
            pcntl_signal($signal, function () use ($signal) {
                TerminatingConsole::handle();
                Workbench::flush();

                $this->unregisterSignals();

                $status = match ($signal) {
                    SIGINT => 130,
                    SIGTERM => 143,
                    default => 128 + $signal,
                };

                if ($status === 130) {
                    exit;
                }

                exit($status);
            });

            $this->registeredSignals[] = $signal;
        }
    }

    /**
     * Restore default signal handlers.
     */
    protected function unregisterSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        foreach ($this->registeredSignals as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        $this->registeredSignals = [];

        if ($this->previousAsyncSignals !== null) {
            pcntl_async_signals($this->previousAsyncSignals);
            $this->previousAsyncSignals = null;
        }
    }
}
