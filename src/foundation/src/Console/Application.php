<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Closure;
use Hyperf\Command\Command;
use Hyperf\Context\Context;
use Hypervel\Container\Contracts\Container as ContainerContract;
use Hypervel\Foundation\Console\Contracts\Application as ApplicationContract;
use Hypervel\Support\ProcessUtils;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Application as SymfonyApplication;
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

class Application extends SymfonyApplication implements ApplicationContract
{
    /**
     * Indicates if the application has "booted".
     */
    protected bool $booted = false;

    /**
     * The output from the previous command.
     */
    protected string $lastOutputContextKey = 'console.last_output';

    /**
     * The console application bootstrappers.
     */
    protected array $bootstrappers = [];

    /**
     * A map of command names to classes.
     */
    protected array $commandMap = [];

    public function __construct(
        protected ContainerContract $container,
        protected EventDispatcherInterface $dispatcher,
        string $version
    ) {
        parent::__construct('Hypervel', $version);

        if ($dispatcher instanceof EventDispatcher) {
            $this->setDispatcher($dispatcher);
            $this->setSignalsToDispatchEvent();
        }

        $this->setAutoExit(false);
        $this->setCatchExceptions(true);

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
     */
    public function starting(Closure $callback): void
    {
        $this->bootstrappers[] = $callback;
    }

    /**
     * Bootstrap the console application.
     */
    protected function bootstrap(): void
    {
        foreach ($this->bootstrappers as $bootstrapper) {
            $bootstrapper($this);
        }
    }

    /**
     * Clear the console application bootstrappers.
     */
    public function forgetBootstrappers(): void
    {
        $this->bootstrappers = [];
    }

    /**
     * Run an Artisan console command by name.
     *
     * @throws \Symfony\Component\Console\Exception\CommandNotFoundException
     */
    public function call(string $command, array $parameters = [], ?OutputInterface $outputBuffer = null): int
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
    protected function parseCommand(string $command, array $parameters): array
    {
        if (is_subclass_of($command, SymfonyCommand::class)) {
            $callingClass = true;

            $command = $this->container->get($command)->getName();
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
     * Add a command, resolving through the application.
     */
    public function resolve(Command|string $command): ?SymfonyCommand
    {
        if (is_subclass_of($command, SymfonyCommand::class) && ($commandName = $command::getDefaultName())) {
            foreach (explode('|', $commandName) as $name) {
                $this->commandMap[$name] = $command;
            }

            return null;
        }

        if ($command instanceof Command) {
            return $this->add($command);
        }

        return $this->add(
            $this->container->get($command)
        );
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

    public function getContainer(): ContainerContract
    {
        return $this->container;
    }
}
