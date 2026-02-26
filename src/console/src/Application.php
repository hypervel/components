<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Closure;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Context\Context;
use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Event\Dispatcher;
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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\PhpExecutableFinder;

class Application extends SymfonyApplication implements ConsoleApplicationContract
{
    /**
     * The output from the previous command.
     */
    protected string $lastOutputContextKey = 'console.last_output';

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
        $this->setCatchExceptions(true);

        $this->dispatcher->dispatch(new ArtisanStarting($this));

        $this->bootstrap();
    }

    /**
     * Determine the proper PHP executable.
     */
    public static function phpBinary(): string
    {
        return ProcessUtils::escapeArgument(
            (new PhpExecutableFinder())->find(false)
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

        return $this->run(
            $input,
            Context::set($this->lastOutputContextKey, $outputBuffer ?: new BufferedOutput())
        );
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
        $lastOutput = Context::get($this->lastOutputContextKey);

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
            $command->setApp($this->container);
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
     * Add a command, resolving through the application.
     *
     * Commands whose name can be determined statically (#[AsCommand], $signature,
     * or $name property) are added to the commandMap for lazy resolution via
     * ContainerCommandLoader. Commands without a static name fall back to eager
     * resolution through the container.
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
     * Checks (in order): #[AsCommand] attribute, $signature property default,
     * $name property default. Returns null if the name can only be determined
     * at construction time.
     */
    protected static function extractCommandName(string $command): ?string
    {
        $reflection = new ReflectionClass($command);

        // 1. #[AsCommand] attribute (Symfony standard, used by Laravel).
        $attributes = $reflection->getAttributes(AsCommand::class);

        if (! empty($attributes)) {
            return $attributes[0]->newInstance()->name;
        }

        $defaults = $reflection->getDefaultProperties();

        // 2. $signature property (Laravel convention) — name is the first token.
        if (! empty($defaults['signature'])) {
            return preg_match('/^\S+/', trim($defaults['signature']), $matches)
                ? $matches[0]
                : null;
        }

        // 3. $name property.
        if (! empty($defaults['name'])) {
            return $defaults['name'];
        }

        return null;
    }

    /**
     * Resolve an array of commands through the application.
     *
     * @param array|mixed $commands
     * @return $this
     */
    public function resolveCommands($commands): static
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        foreach ($commands as $command) {
            $this->resolve($command);
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
     * Get the application instance.
     */
    public function getApp(): ApplicationContract
    {
        return $this->container;
    }
}
