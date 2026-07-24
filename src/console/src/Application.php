<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Closure;
use Hypervel\Console\Attributes\Aliases;
use Hypervel\Console\Attributes\Signature;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\ProcessUtils;
use Override;
use ReflectionClass;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\PhpExecutableFinder;

class Application extends SymfonyApplication implements ConsoleApplicationContract
{
    /**
     * The CoroutineContext key holding the output from the previous command.
     */
    protected const LAST_OUTPUT_CONTEXT_KEY = '__console.last_output';

    /**
     * The console application bootstrappers.
     *
     * @var array<array-key, Closure(static): void>
     */
    protected static array $bootstrappers = [];

    /**
     * A map of command names to classes.
     */
    protected array $commandMap = [];

    /**
     * Whether the container command loader has been set.
     */
    protected bool $commandLoaderSet = false;

    public function __construct(
        protected ApplicationContract $container,
        protected Dispatcher $dispatcher,
        string $version
    ) {
        parent::__construct('Hypervel Framework', $version);

        if ($dispatcher instanceof EventDispatcher) {
            $this->setDispatcher($dispatcher);
            $this->setSignalsToDispatchEvent();
        }

        $this->setAutoExit(false);
        $this->setCatchExceptions(false);

        $this->dispatcher->dispatch(new ArtisanStarting($this));

        $this->bootstrap();
    }

    // Composer can preload an older, untyped Symfony Console application before
    // running Hypervel scripts. Keep these contract methods declared locally so
    // their return types do not depend on the Symfony version already loaded.

    /**
     * Get all commands registered with the application.
     */
    #[Override]
    public function all(?string $namespace = null): array
    {
        return parent::all($namespace);
    }

    /**
     * Run the console application.
     */
    #[Override]
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        return parent::run($input, $output);
    }

    /**
     * Determine the proper PHP executable.
     */
    public static function phpBinary(): string
    {
        return ProcessUtils::escapeArgument(
            (new PhpExecutableFinder)->find(false)
        );
    }

    /**
     * Determine the proper Artisan executable.
     */
    public static function artisanBinary(): string
    {
        return ProcessUtils::escapeArgument(
            defined('ARTISAN_BINARY') ? ARTISAN_BINARY : 'artisan'
        );
    }

    /**
     * Format the given command as a fully-qualified executable command.
     */
    public static function formatCommandString(string $string): string
    {
        return sprintf('%s %s %s', static::phpBinary(), static::artisanBinary(), $string);
    }

    /**
     * Register a console "starting" bootstrapper.
     *
     * Boot-only. The bootstrapper persists in a static property for the worker
     * lifetime and runs for every subsequent console application instance.
     *
     * @param Closure(static): void $callback
     */
    public static function starting(Closure $callback): void
    {
        static::$bootstrappers[] = $callback;
    }

    /**
     * Bootstrap the console application.
     */
    protected function bootstrap(): void
    {
        foreach (static::$bootstrappers as $bootstrapper) {
            $bootstrapper($this);
        }
    }

    /**
     * Clear the console application bootstrappers.
     *
     * Boot or tests only. Clears worker-wide console bootstrappers; concurrent
     * console application creation may observe different startup hooks.
     */
    public static function forgetBootstrappers(): void
    {
        static::$bootstrappers = [];
    }

    /**
     * Run an Artisan console command by name.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call(string|SymfonyCommand $command, array $parameters = [], ?OutputInterface $outputBuffer = null): int
    {
        [$command, $input] = $this->parseCommand($command, $parameters);

        if (! $this->has($command)) {
            throw new CommandNotFoundException(sprintf('The command "%s" does not exist.', $command));
        }

        return $this->runProgrammatically(
            $input,
            CoroutineContext::set(
                self::LAST_OUTPUT_CONTEXT_KEY,
                $outputBuffer ?: new BufferedOutput,
            ),
        );
    }

    /**
     * Run a command without Symfony's process-global CLI wrapper.
     *
     * Symfony's run() owns root CLI concerns: terminal environment export,
     * process-global exception handling, shell-verbosity restoration, and
     * auto-exit. Programmatic calls need explicit IO configuration and
     * doRun() only.
     * Keep this boundary aligned with Symfony through the parity tests.
     *
     * @see SymfonyApplication::run()
     */
    protected function runProgrammatically(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->configureProgrammaticIO($input, $output);

        return $this->doRun($input, $output);
    }

    /**
     * Configure the input and output for a programmatic command.
     */
    protected function configureProgrammaticIO(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasParameterOption(['--ansi'], true)) {
            $output->setDecorated(true);
        } elseif ($input->hasParameterOption(['--no-ansi'], true)) {
            $output->setDecorated(false);
        }

        $shellVerbosity = match (true) {
            $input->hasParameterOption(['--silent'], true) => -2,
            $input->hasParameterOption(['--quiet', '-q'], true) => -1,
            $input->hasParameterOption('-vvv', true)
                || $input->hasParameterOption('--verbose=3', true)
                || $input->getParameterOption('--verbose', false, true) === 3 => 3,
            $input->hasParameterOption('-vv', true)
                || $input->hasParameterOption('--verbose=2', true)
                || $input->getParameterOption('--verbose', false, true) === 2 => 2,
            $input->hasParameterOption('-v', true)
                || $input->hasParameterOption('--verbose=1', true)
                || $input->hasParameterOption('--verbose', true)
                || $input->getParameterOption('--verbose', false, true) => 1,
            default => 0,
        };

        $output->setVerbosity(match ($shellVerbosity) {
            -2 => OutputInterface::VERBOSITY_SILENT,
            -1 => OutputInterface::VERBOSITY_QUIET,
            1 => OutputInterface::VERBOSITY_VERBOSE,
            2 => OutputInterface::VERBOSITY_VERY_VERBOSE,
            3 => OutputInterface::VERBOSITY_DEBUG,
            default => $output->getVerbosity(),
        });

        if ($shellVerbosity < 0
            || $input->hasParameterOption(['--no-interaction', '-n'], true)
        ) {
            $input->setInteractive(false);
        }
    }

    /**
     * Parse the incoming Artisan command and its input.
     */
    protected function parseCommand(string|SymfonyCommand $command, array $parameters): array
    {
        if (is_subclass_of($command, SymfonyCommand::class)) {
            $callingClass = true;

            if (is_object($command)) {
                $command = get_class($command);
            }

            $command = $this->container->make($command)->getName();
        }

        if (! isset($callingClass) && empty($parameters)) {
            $command = $this->getCommandName($input = new StringInput($command));
        } else {
            array_unshift($parameters, $command);

            $input = new ArrayInput($parameters);
        }

        return [$command, $input];
    }

    /**
     * Get the output for the last run command.
     */
    public function output(): string
    {
        $lastOutput = CoroutineContext::get(self::LAST_OUTPUT_CONTEXT_KEY);

        return $lastOutput && method_exists($lastOutput, 'fetch')
            ? $lastOutput->fetch()
            : '';
    }

    /**
     * Alias for addCommand() since Symfony's add() method was deprecated.
     */
    public function add(SymfonyCommand $command): ?SymfonyCommand
    {
        return $this->addCommand($command);
    }

    /**
     * Add a command to the console.
     */
    public function addCommand(SymfonyCommand|callable $command): ?SymfonyCommand
    {
        if ($command instanceof Command) {
            $command->setHypervel($this->container);
        }

        return $this->addToParent($command);
    }

    /**
     * Add the command to the parent instance.
     */
    protected function addToParent(SymfonyCommand|callable $command): ?SymfonyCommand
    {
        return parent::addCommand($command);
    }

    /**
     * Run the given command instance.
     */
    #[Override]
    protected function doRunCommand(SymfonyCommand $command, InputInterface $input, OutputInterface $output): int
    {
        return parent::doRunCommand(
            $this->freshCommandForRun($command),
            $input,
            $output
        );
    }

    /**
     * Create a per-execution command instance from the cached command prototype.
     *
     * Symfony caches command objects after lazy resolution, but Hypervel commands
     * store input/output state while running. Execute clones so concurrent calls
     * to the same command cannot overwrite each other's per-run state.
     */
    protected function freshCommandForRun(SymfonyCommand $command): SymfonyCommand
    {
        $command = clone $command;

        $command->setApplication($this);

        if ($command instanceof Command) {
            $command->setHypervel($this->container);
        }

        return $command;
    }

    /**
     * Add a command, resolving through the application.
     *
     * Commands whose name can be determined statically (#[AsCommand],
     * #[Signature], $signature, or $name property) are added to the commandMap
     * for lazy resolution via ContainerCommandLoader. Commands without a static
     * name fall back to eager resolution through the container.
     */
    public function resolve(SymfonyCommand|string $command): ?SymfonyCommand
    {
        if ($command instanceof SymfonyCommand) {
            return $this->addCommand($command);
        }

        if (is_subclass_of($command, SymfonyCommand::class)) {
            $commandName = static::extractCommandName($command);

            if ($commandName !== null) {
                foreach (explode('|', $commandName) as $name) {
                    $this->commandMap[$name] = $command;
                }

                foreach (static::extractCommandAliases($command) as $alias) {
                    $this->commandMap[$alias] = $command;
                }

                // Refresh the loader so it sees the new commandMap entries.
                if ($this->commandLoaderSet) {
                    $this->setContainerCommandLoader();
                }

                return null;
            }
        }

        // Eager fallback for commands whose name cannot be determined statically.
        return $this->addCommand($this->container->make($command));
    }

    /**
     * Extract the command name from a class without instantiation.
     *
     * Checks (in order): #[AsCommand] attribute, #[Signature] attribute,
     * $signature property default, $name property default. Returns null if the
     * name can only be determined at construction time.
     */
    protected static function extractCommandName(string $command): ?string
    {
        $reflection = new ReflectionClass($command);

        // 1. #[AsCommand] attribute (Symfony standard, used by Laravel).
        $attributes = $reflection->getAttributes(AsCommand::class);

        if (! empty($attributes)) {
            return $attributes[0]->newInstance()->name;
        }

        // 2. #[Signature] attribute (Hypervel command metadata).
        $attributes = $reflection->getAttributes(Signature::class);

        if (! empty($attributes)) {
            return static::extractCommandNameFromSignature(
                $attributes[0]->newInstance()->signature
            );
        }

        $defaults = $reflection->getDefaultProperties();

        // 3. $signature property (Laravel convention) — name is the first token.
        if (! empty($defaults['signature'])) {
            return static::extractCommandNameFromSignature($defaults['signature']);
        }

        // 4. $name property.
        if (! empty($defaults['name'])) {
            return $defaults['name'];
        }

        return null;
    }

    /**
     * Extract the command name from a signature string.
     */
    protected static function extractCommandNameFromSignature(string $signature): ?string
    {
        return preg_match('/^\S+/', trim($signature), $matches)
            ? $matches[0]
            : null;
    }

    /**
     * Extract command aliases from a class without instantiation.
     *
     * Reads #[Aliases] first, then #[Signature] aliases, then the $aliases property default.
     * #[AsCommand] aliases are already baked into the name string as
     * pipe-separated values by Symfony, so they are handled by
     * extractCommandName() + the explode('|') in resolve().
     *
     * @return array<int, string>
     */
    protected static function extractCommandAliases(string $command): array
    {
        $reflection = new ReflectionClass($command);
        $attributes = $reflection->getAttributes(Aliases::class);

        if (! empty($attributes)) {
            return $attributes[0]->newInstance()->aliases;
        }

        $attributes = $reflection->getAttributes(Signature::class);

        if (! empty($attributes)) {
            $aliases = $attributes[0]->newInstance()->aliases;

            if ($aliases !== null) {
                return $aliases;
            }
        }

        $defaults = $reflection->getDefaultProperties();

        return $defaults['aliases'] ?? [];
    }

    /**
     * Resolve an array of commands through the application.
     *
     * @param array<string|SymfonyCommand>|string|SymfonyCommand ...$commands
     * @return $this
     */
    public function resolveCommands(array|SymfonyCommand|string ...$commands): static
    {
        foreach ($commands as $command) {
            foreach (is_array($command) ? $command : [$command] as $resolvable) {
                $this->resolve($resolvable);
            }
        }

        return $this;
    }

    /**
     * Set the container command loader for lazy resolution.
     *
     * @return $this
     */
    public function setContainerCommandLoader(): static
    {
        $this->setCommandLoader(
            new ContainerCommandLoader($this->container, $this->commandMap)
        );

        $this->commandLoaderSet = true;

        return $this;
    }

    /**
     * Get the default input definition for the application.
     *
     * This is used to add the --env option to every available command.
     */
    #[Override]
    protected function getDefaultInputDefinition(): InputDefinition
    {
        return tap(parent::getDefaultInputDefinition(), function ($definition) {
            $definition->addOption($this->getEnvironmentOption());
        });
    }

    /**
     * Get the global environment option for the definition.
     *
     * @return InputOption
     */
    protected function getEnvironmentOption()
    {
        $message = 'The environment the command should run under';

        return new InputOption('--env', null, InputOption::VALUE_OPTIONAL, $message);
    }

    /**
     * Get the Hypervel application instance.
     */
    public function getHypervel(): ApplicationContract
    {
        return $this->container;
    }
}
