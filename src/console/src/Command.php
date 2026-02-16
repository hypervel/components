<?php

declare(strict_types=1);

namespace Hypervel\Console;

use FriendsOfHyperf\CommandSignals\Traits\InteractsWithSignals;
use FriendsOfHyperf\PrettyConsole\Traits\Prettyable;
use Hypervel\Console\Contracts\CommandMutex;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\AfterHandle;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Console\Events\FailToHandle;
use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Isolatable;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Swoole\ExitException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function Hypervel\Coroutine\run;
use function Hypervel\Support\swoole_hook_flags;

abstract class Command extends SymfonyCommand
{
    use InteractsWithSignals;
    use Prettyable;
    use Traits\DisableEventDispatcher;
    use Traits\HasParameters;
    use Traits\InteractsWithIO;

    /**
     * The name of the command.
     */
    protected ?string $name = null;

    /**
     * The description of the command.
     */
    protected string $description = '';

    /**
     * Whether to execute in a coroutine environment.
     */
    protected bool $coroutine = true;

    /**
     * The event dispatcher instance.
     */
    protected ?Dispatcher $eventDispatcher = null;

    /**
     * The hook flags for the coroutine.
     */
    protected int $hookFlags = -1;

    /**
     * The name and signature of the command.
     */
    protected ?string $signature = null;

    /**
     * The exit code of the command.
     */
    protected int $exitCode = self::SUCCESS;

    protected ApplicationContract $app;

    /**
     * Indicates whether only one instance of the command can run at any given time.
     */
    protected bool $isolated = false;

    /**
     * The default exit code for isolated commands.
     */
    protected int $isolatedExitCode = self::SUCCESS;

    public function __construct(?string $name = null)
    {
        $this->name = $name ?? $this->name;

        if ($this->hookFlags < 0) {
            $this->hookFlags = swoole_hook_flags();
        }

        if (isset($this->signature)) {
            $this->configureUsingFluentDefinition();
        } else {
            parent::__construct($this->name);
        }

        $this->addDisableDispatcherOption();

        if (! empty($this->description)) {
            $this->setDescription($this->description);
        }

        if (! isset($this->signature)) {
            $this->specifyParameters();
        }

        /* @phpstan-ignore assign.propertyType */
        $this->app = Container::getInstance();

        if ($this instanceof Isolatable) {
            $this->configureIsolation();
        }
    }

    /**
     * Run the console command.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->output = new SymfonyStyle($input, $output);

        $this->setUpTraits($this->input = $input, $this->output);

        return parent::run($this->input, $this->output);
    }

    /**
     * Call another console command.
     */
    public function call(string $command, array $arguments = []): int
    {
        $arguments['command'] = $command;

        return $this->getApplication()->find($command)->run($this->createInputFromArguments($arguments), $this->output);
    }

    /**
     * Configure the console command for isolation.
     */
    protected function configureIsolation(): void
    {
        $this->getDefinition()->addOption(new InputOption(
            'isolated',
            null,
            InputOption::VALUE_OPTIONAL,
            'Do not run the command if another instance of the command is already running',
            $this->isolated
        ));
    }

    /**
     * Configure the console command using a fluent definition.
     */
    protected function configureUsingFluentDefinition(): void
    {
        [$name, $arguments, $options] = Parser::parse($this->signature);

        parent::__construct($this->name = $name);

        // After parsing the signature we will spin through the arguments and options
        // and set them on this command. These will already be changed into proper
        // instances of these "InputArgument" and "InputOption" Symfony classes.
        $this->getDefinition()->addArguments($arguments);
        $this->getDefinition()->addOptions($options);
    }

    protected function configure(): void
    {
        parent::configure();
    }

    /**
     * Create an input instance from the given arguments.
     */
    protected function createInputFromArguments(array $arguments): ArrayInput
    {
        return tap(new ArrayInput(array_merge($this->context(), $arguments)), function (InputInterface $input) {
            if ($input->hasParameterOption(['--no-interaction'], true)) {
                $input->setInteractive(false);
            }
        });
    }

    /**
     * Get all the context passed to the command.
     */
    protected function context(): array
    {
        return collect($this->input->getOptions())->only([
            'ansi',
            'no-ansi',
            'no-interaction',
            'quiet',
            'verbose',
        ])->filter()->mapWithKeys(function ($value, $key) {
            return ["--{$key}" => $value];
        })->all();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->disableDispatcher($input);
        $this->replaceOutput();

        // Check if the command should be isolated and if another instance is running
        if ($this instanceof Isolatable
            && $this->option('isolated') !== false
            && ! $this->commandIsolationMutex()->create($this)
        ) {
            $this->comment(sprintf(
                'The [%s] command is already running.',
                $this->getName()
            ));

            return (int) (is_numeric($this->option('isolated'))
                ? $this->option('isolated')
                : $this->isolatedExitCode);
        }

        $method = method_exists($this, 'handle') ? 'handle' : '__invoke';

        $callback = function () use ($method): int {
            try {
                $this->eventDispatcher?->dispatch(new BeforeHandle($this));
                /* @phpstan-ignore-next-line */
                $statusCode = $this->app->call([$this, $method]);
                if (is_int($statusCode)) {
                    $this->exitCode = $statusCode;
                }
                $this->eventDispatcher?->dispatch(new AfterHandle($this));
            } catch (ManuallyFailedException $e) {
                $this->components->error($e->getMessage());

                return $this->exitCode = static::FAILURE;
            } catch (Throwable $exception) {
                if (class_exists(ExitException::class) && $exception instanceof ExitException) {
                    return $this->exitCode = (int) $exception->getStatus();
                }

                if (! $this->eventDispatcher) {
                    throw $exception;
                }

                (new ErrorRenderer($this->input, $this->output))
                    ->render($exception);

                $this->exitCode = self::FAILURE;

                $this->eventDispatcher->dispatch(new FailToHandle($this, $exception));
            } finally {
                $this->eventDispatcher?->dispatch(new AfterExecute($this, $exception ?? null));

                // Release the isolation mutex if applicable
                if ($this instanceof Isolatable && $this->option('isolated') !== false) {
                    $this->commandIsolationMutex()->forget($this);
                }
            }

            return $this->exitCode;
        };

        if ($this->coroutine && ! Coroutine::inCoroutine()) {
            run($callback, $this->hookFlags);
        } else {
            $callback();
        }

        return $this->exitCode >= 0 && $this->exitCode <= 255 ? $this->exitCode : self::INVALID;
    }

    /**
     * Get a command isolation mutex instance for the command.
     */
    protected function commandIsolationMutex(): CommandMutex
    {
        return $this->app->bound(CommandMutex::class)
            ? $this->app->make(CommandMutex::class)
            : $this->app->make(CacheCommandMutex::class);
    }

    protected function replaceOutput(): void
    {
        if ($this->app->bound(OutputInterface::class)) {
            $this->output = $this->app->make(OutputInterface::class); // @phpstan-ignore assign.propertyType (PendingCommand binds a SymfonyStyle mock)
        }
    }

    /**
     * Fail the command manually.
     *
     * @throws ManuallyFailedException|Throwable
     */
    public function fail(string|Throwable|null $exception = null): void
    {
        if (is_null($exception)) {
            $exception = 'Command failed manually.';
        }

        if (is_string($exception)) {
            $exception = new ManuallyFailedException($exception);
        }

        throw $exception;
    }

    /**
     * Call another console command without output.
     */
    public function callSilent(string $command, array $arguments = []): int
    {
        return $this->app
            ->get(KernelContract::class)
            ->call($command, $arguments);
    }

    /**
     * Set up the traits used by the command.
     */
    protected function setUpTraits(InputInterface $input, OutputInterface $output): array
    {
        $uses = array_flip(class_uses_recursive(static::class));

        foreach ($uses as $trait) {
            if (method_exists($this, $method = 'setUp' . class_basename($trait))) {
                $this->{$method}($input, $output);
            }
        }

        return $uses;
    }
}
