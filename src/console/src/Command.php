<?php

declare(strict_types=1);

namespace Hypervel\Console;

use FriendsOfHyperf\CommandSignals\Traits\InteractsWithSignals;
use FriendsOfHyperf\PrettyConsole\Traits\Prettyable;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Event\AfterExecute;
use Hyperf\Command\Event\AfterHandle;
use Hyperf\Command\Event\BeforeHandle;
use Hyperf\Command\Event\FailToHandle;
use Hypervel\Console\Contracts\CommandMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Isolatable;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Swoole\ExitException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Coroutine\run;

abstract class Command extends HyperfCommand
{
    use InteractsWithSignals;
    use Prettyable;

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
        parent::__construct($name);

        /** @var ApplicationContract $app */
        $app = Container::getInstance();
        $this->app = $app;

        if ($this instanceof Isolatable) {
            $this->configureIsolation();
        }
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
            ? $this->app->get(CommandMutex::class)
            : $this->app->get(CacheCommandMutex::class);
    }

    protected function replaceOutput(): void
    {
        if ($this->app->bound(OutputInterface::class)) {
            $this->output = $this->app->get(OutputInterface::class); // @phpstan-ignore assign.propertyType (PendingCommand binds a SymfonyStyle mock)
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
}
