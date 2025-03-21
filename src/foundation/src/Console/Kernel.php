<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Closure;
use Exception;
use Hyperf\Collection\Arr;
use Hyperf\Command\Annotation\Command as AnnotationCommand;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\ReflectionManager;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Stringable\Str;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\ClosureCommand;
use Hypervel\Console\Contracts\Application as ApplicationContract;
use Hypervel\Console\HasPendingCommand;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Hypervel\Foundation\Contracts\Application as ContainerContract;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Hyperf\Tappable\tap;
use function Hypervel\Support\env;

class Kernel implements KernelContract
{
    use HasPendingCommand;

    protected ApplicationContract $artisan;

    /**
     * The Artisan commands provided by the application.
     */
    protected array $commands = [];

    /**
     * Registered closure commands.
     */
    protected array $closureCommands = [];

    /**
     * The paths where Artisan commands should be automatically discovered.
     */
    protected array $commandPaths = [];

    /**
     * The paths where Artisan "routes" should be automatically discovered.
     */
    protected array $commandRoutePaths = [];

    /**
     * Indicates if the Closure commands have been loaded.
     */
    protected bool $commandsLoaded = false;

    /**
     * The commands paths that have been "loaded".
     */
    protected array $loadedPaths = [];

    /**
     * The console application bootstrappers.
     */
    protected array $bootstrappers = [
        \Hypervel\Foundation\Bootstrap\RegisterFacades::class,
        \Hypervel\Foundation\Bootstrap\RegisterProviders::class,
        \Hypervel\Foundation\Bootstrap\BootProviders::class,
    ];

    public function __construct(
        protected ContainerContract $app,
        protected EventDispatcherInterface $events
    ) {
        if (! defined('ARTISAN_BINARY')) {
            define('ARTISAN_BINARY', 'artisan');
        }

        $events->dispatch(new BootApplication());

        $this->app->booted(function () {
            $this->defineConsoleSchedule();
        });
    }

    /**
     * Run the console application.
     */
    public function handle(InputInterface $input, ?OutputInterface $output = null): mixed
    {
        return $this->getArtisan()->run($input, $output);
    }

    /**
     * Bootstrap the application for artisan commands.
     */
    public function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }

        if (! $this->commandsLoaded) {
            $this->commands();

            if ($this->shouldDiscoverCommands()) {
                $this->discoverCommands();
            }

            $this->loadCommands();

            $this->commandsLoaded = true;
        }
    }

    /**
     * Determine if the kernel should discover commands.
     */
    protected function shouldDiscoverCommands(): bool
    {
        return get_class($this) === __CLASS__;
    }

    /**
     * Discover the commands that should be automatically loaded.
     */
    protected function discoverCommands(): void
    {
        foreach ($this->commandPaths as $path) {
            $this->load($path);
        }

        foreach ($this->commandRoutePaths as $path) {
            if (file_exists($path)) {
                require $path;
            }
        }
    }

    /**
     * Collect commands from all possible sources.
     */
    protected function collectCommands(): array
    {
        // Load commands from the given directory.
        $loadedPathReflections = [];
        if ($loadedPaths = $this->getLoadedPaths()) {
            $loadedPathReflections = ReflectionManager::getAllClasses($loadedPaths);
        }

        // Load commands from Hyperf config for compatibility.
        $configReflections = array_map(function (string $class) {
            return ReflectionManager::reflectClass($class);
        }, $this->app->get(ConfigInterface::class)->get('commands', []));

        // Load commands that defined by annotation.
        $annotationReflections = [];
        if (class_exists(AnnotationCollector::class) && class_exists(AnnotationCommand::class)) {
            $annotationAnnotationCommands = AnnotationCollector::getClassesByAnnotation(AnnotationCommand::class);
            $annotationReflections = array_map(function (string $class) {
                return ReflectionManager::reflectClass($class);
            }, array_keys($annotationAnnotationCommands));
        }

        $reflections = array_merge($loadedPathReflections, $configReflections, $annotationReflections);
        $commands = [];
        // Filter valid command classes.
        foreach ($reflections as $reflection) {
            $command = $reflection->getName();
            if (! is_subclass_of($command, SymfonyCommand::class)) {
                continue;
            }
            $commands[] = $command;
        }

        // Load commands from registered closures
        foreach ($this->closureCommands as $command) {
            $closureId = spl_object_hash($command);
            $this->app->set($commandId = "commands.{$closureId}", $command);
            $commands[] = $commandId;
        }

        return $commands;
    }

    protected function loadCommands(): void
    {
        $commands = $this->collectCommands();

        // Sort commands by namespace to make sure override commands work.
        foreach ($commands as $key => $command) {
            if (Str::startsWith($command, 'Hyperf\\')) {
                unset($commands[$key]);
                array_unshift($commands, $command);
            }
        }

        // Register commands to application.
        foreach ($commands as $command) {
            $this->registerCommand($command);
        }
    }

    /**
     * Register the given command with the console application.
     */
    public function registerCommand(string $command): void
    {
        if (! $command = $this->pendingCommand($this->app->get($command))) {
            return;
        }

        $this->getArtisan()->add($command);
    }

    /**
     * Run an Artisan console command by name.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call(string $command, array $parameters = [], ?OutputInterface $outputBuffer = null)
    {
        return $this->getArtisan()->call($command, $parameters, $outputBuffer);
    }

    /**
     * Get all of the commands registered with the console.
     */
    public function all(): array
    {
        return $this->getArtisan()->all();
    }

    /**
     * Get the output for the last run command.
     */
    public function output(): string
    {
        return $this->getArtisan()->output();
    }

    /**
     * Define the application's command schedule.
     */
    public function schedule(Schedule $schedule): void
    {
    }

    /**
     * Resolve a console schedule instance.
     */
    public function resolveConsoleSchedule(): Schedule
    {
        return tap(new Schedule($this->scheduleTimezone()), function ($schedule) {
            $this->schedule($schedule->useCache($this->scheduleCache()));
        });
    }

    /**
     * Define the application's command schedule.
     */
    protected function defineConsoleSchedule(): void
    {
        $this->app->bind(Schedule::class, function ($app) {
            return tap(new Schedule($this->scheduleTimezone()), function ($schedule) {
                $this->schedule($schedule->useCache($this->scheduleCache()));
            });
        });
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone(): ?string
    {
        $config = $this->app['config'];

        return $config->get('app.schedule_timezone', $config->get('app.timezone'));
    }

    /**
     * Get the name of the cache store that should manage scheduling mutexes.
     */
    protected function scheduleCache(): ?string
    {
        return $this->app['config']->get('cache.schedule_store', env('SCHEDULE_CACHE_DRIVER'));
    }

    /**
     * Register the commands for the application.
     */
    public function commands(): void
    {
    }

    /**
     * Register a Closure based command with the application.
     */
    public function command(string $signature, Closure $callback): ClosureCommand
    {
        $command = new ClosureCommand($this->app, $signature, $callback);

        // If the commands have already been loaded, we will register it
        // with the console right away. If not, we will defer the call
        // to this registration by storing the commands closures.
        if ($this->commandsLoaded) {
            $closureId = spl_object_hash($command);
            $this->app->set($commandId = "commands.{$closureId}", $command);
            $this->registerCommand($commandId);
        } else {
            $this->closureCommands[] = $command;
        }

        return $command;
    }

    /**
     * Add loadedPaths in the given directory.
     */
    public function load(array|string $paths): void
    {
        $paths = array_unique(Arr::wrap($paths));

        $paths = array_filter($paths, function ($path) {
            return is_dir($path);
        });

        if (empty($paths)) {
            return;
        }

        $this->loadedPaths = array_values(
            array_unique(array_merge($this->loadedPaths, $paths))
        );
    }

    /**
     * Get loadedPaths for the application.
     */
    public function getLoadedPaths(): array
    {
        return $this->loadedPaths;
    }

    /**
     * Set the Artisan commands provided by the application.
     */
    public function addCommands(array $commands): static
    {
        $this->commands = array_values(
            array_unique(
                array_merge($this->commands, $commands)
            )
        );

        return $this;
    }

    /**
     * Set the paths that should have their Artisan commands automatically discovered.
     */
    public function addCommandPaths(array $paths): static
    {
        $this->commandPaths = array_values(array_unique(array_merge($this->commandPaths, $paths)));

        return $this;
    }

    /**
     * Set the paths that should have their Artisan "routes" automatically discovered.
     */
    public function addCommandRoutePaths(array $paths): static
    {
        $this->commandRoutePaths = array_values(array_unique(array_merge($this->commandRoutePaths, $paths)));

        return $this;
    }

    /**
     * Get the bootstrap classes for the application.
     */
    protected function bootstrappers(): array
    {
        return $this->bootstrappers;
    }

    /**
     * Get the Artisan application instance.
     */
    public function getArtisan(): ApplicationContract
    {
        if (isset($this->artisan)) {
            return $this->artisan;
        }

        $this->artisan = (new ConsoleApplication($this->app, $this->events, $this->app->version()))
            ->resolveCommands($this->commands)
            ->setContainerCommandLoader();

        $this->app->instance(ApplicationInterface::class, $this->artisan);

        $this->bootstrap();

        return $this->artisan;
    }

    /**
     * Set the Artisan application instance.
     */
    public function setArtisan(ApplicationContract $artisan): void
    {
        $this->artisan = $artisan;
    }

    /**
     * Runs the current application.
     *
     * @return int 0 if everything went fine, or an error code
     *
     * @throws Exception When running fails. Bypass this when {@link setCatchExceptions()}.
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        return $this->getArtisan()->run($input, $output);
    }
}
