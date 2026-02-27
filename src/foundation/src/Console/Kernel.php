<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Exception;
use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Events\CommandFinished;
use Hypervel\Console\Events\CommandStarting;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Console\Application as ApplicationContract;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ContainerContract;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\Support\Arr;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use WeakMap;

class Kernel implements KernelContract
{
    use InteractsWithTime;

    protected ApplicationContract $artisan;

    /**
     * The Symfony event dispatcher implementation.
     */
    protected ?EventDispatcher $symfonyDispatcher = null;

    /**
     * The Artisan commands provided by the application.
     */
    protected array $commands = [];

    /**
     * All of the registered command duration handlers.
     */
    protected array $commandLifecycleDurationHandlers = [];

    /**
     * When the currently handled command started.
     */
    protected ?Carbon $commandStartedAt = null;

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
        protected Dispatcher $events
    ) {
        if (! defined('ARTISAN_BINARY')) {
            define('ARTISAN_BINARY', 'artisan');
        }

        $events->dispatch(new BootApplication());

        $this->app->booted(function () {
            if (! $this->app->runningUnitTests()) {
                $this->rerouteSymfonyCommandEvents();
            }

            $this->defineConsoleSchedule();
        });
    }

    /**
     * Re-route the Symfony command events to their Hypervel counterparts.
     *
     * @internal
     */
    public function rerouteSymfonyCommandEvents(): static
    {
        if (is_null($this->symfonyDispatcher)) {
            $this->symfonyDispatcher = new EventDispatcher();

            $this->symfonyDispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleCommandEvent $event) {
                $this->events->dispatch(
                    new CommandStarting($event->getCommand()?->getName() ?? '', $event->getInput(), $event->getOutput())
                );
            });

            $this->symfonyDispatcher->addListener(ConsoleEvents::TERMINATE, function (ConsoleTerminateEvent $event) {
                $this->events->dispatch(
                    new CommandFinished($event->getCommand()?->getName() ?? '', $event->getInput(), $event->getOutput(), $event->getExitCode())
                );
            });
        }

        // If the Artisan application was already created (e.g. during test
        // bootstrap), wire the dispatcher to it now so events still fire.
        if (isset($this->artisan) && $this->artisan instanceof \Symfony\Component\Console\Application) {
            $this->artisan->setDispatcher($this->symfonyDispatcher);
            $this->artisan->setSignalsToDispatchEvent();
        }

        return $this;
    }

    /**
     * Run the console application.
     */
    public function handle(InputInterface $input, ?OutputInterface $output = null): mixed
    {
        $this->commandStartedAt = Date::now();

        return $this->getArtisan()->run($input, $output);
    }

    /**
     * Terminate the application.
     */
    public function terminate(InputInterface $input, int $status): void
    {
        $this->events->dispatch(new Terminating());

        if ($this->commandStartedAt === null) {
            return;
        }

        $this->commandStartedAt->setTimezone(
            $this->app['config']->get('app.timezone') ?? 'UTC'
        );

        foreach ($this->commandLifecycleDurationHandlers as ['threshold' => $threshold, 'handler' => $handler]) {
            $end ??= Date::now();

            if ($this->commandStartedAt->diffInMilliseconds($end) > $threshold) {
                $handler($this->commandStartedAt, $input, $status);
            }
        }

        $this->commandStartedAt = null;
    }

    /**
     * Register a callback to be invoked when the command lifecycle duration exceeds a given amount of time.
     */
    public function whenCommandLifecycleIsLongerThan(CarbonInterval|DateTimeInterface|float|int $threshold, callable $handler): void
    {
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->commandLifecycleDurationHandlers[] = [
            'threshold' => $threshold,
            'handler' => $handler,
        ];
    }

    /**
     * When the command being handled started.
     */
    public function commandStartedAt(): ?Carbon
    {
        return $this->commandStartedAt;
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
     * Get the Finder instance for discovering command files.
     */
    protected function findCommands(array $paths): Finder
    {
        return Finder::create()->in($paths)->name('*.php')->files();
    }

    /**
     * Extract the command class name from the given file path.
     */
    protected function commandClassFromFile(SplFileInfo $file, string $namespace): string
    {
        return $namespace . str_replace(
            ['/', '.php'],
            ['\\', ''],
            Str::after($file->getRealPath(), realpath($this->app->path()) . DIRECTORY_SEPARATOR)
        );
    }

    /**
     * Register the given command with the console application.
     */
    public function registerCommand(SymfonyCommand $command): void
    {
        $this->getArtisan()->add($command); // @phpstan-ignore argument.type (interface narrower than parent)
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
     * Queue the given console command.
     */
    public function queue(string $command, array $parameters = []): PendingDispatch
    {
        return QueuedCommand::dispatch(func_get_args());
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
        $this->app->singleton(Schedule::class, function ($app) {
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
        $command = new ClosureCommand($signature, $callback);

        if ($this->commandsLoaded) {
            $this->getArtisan()->add($command);
        } else {
            ConsoleApplication::starting(fn (ConsoleApplication $artisan) => $artisan->add($command));
        }

        return $command;
    }

    /**
     * Register all of the commands in the given directory.
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

        $namespace = $this->app->getNamespace();

        $possibleCommands = new WeakMap();

        $filterCommands = function (SplFileInfo $file) use ($namespace, $possibleCommands) {
            $commandClassName = $this->commandClassFromFile($file, $namespace);

            $possibleCommands[$file] = $commandClassName;

            $command = rescue(fn () => new ReflectionClass($commandClassName), null, false);

            return $command instanceof ReflectionClass
                && $command->isSubclassOf(SymfonyCommand::class)
                && ! $command->isAbstract();
        };

        foreach ($this->findCommands($paths)->filter($filterCommands) as $file) {
            ConsoleApplication::starting(function (ConsoleApplication $artisan) use ($file, $possibleCommands) {
                $artisan->resolve($possibleCommands[$file]);
            });
        }
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

        // Bootstrap first so that commands(), discoverCommands(), and load()
        // can register Artisan::starting() callbacks before the Application
        // constructor fires them.
        $this->bootstrap();

        $this->artisan = (new ConsoleApplication($this->app, $this->events, $this->app->version()))
            ->resolveCommands($this->commands)
            ->setContainerCommandLoader();

        $this->app->instance(ApplicationContract::class, $this->artisan);

        if ($this->symfonyDispatcher instanceof EventDispatcher) {
            $this->artisan->setDispatcher($this->symfonyDispatcher); /* @phpstan-ignore-line */
            $this->artisan->setSignalsToDispatchEvent(); /* @phpstan-ignore-line */
        }

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
