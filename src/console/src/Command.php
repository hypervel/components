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
use Hypervel\Context\ApplicationContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Swoole\ExitException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Coroutine\run;

abstract class Command extends HyperfCommand
{
    use InteractsWithSignals;
    use Prettyable;

    protected ApplicationContract $app;

    public function __construct(?string $name = null)
    {
        parent::__construct($name);

        /** @var ApplicationContract $app */
        $app = ApplicationContext::getContainer();
        $this->app = $app;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->disableDispatcher($input);
        $this->replaceOutput();
        $method = method_exists($this, 'handle') ? 'handle' : '__invoke';

        // Capture console state before potentially spawning a new coroutine.
        // This preserves the context set by Kernel::handle() across coroutine boundaries.
        $shouldRunInConsole = $this->app->runningInConsole();

        $callback = function () use ($method, $shouldRunInConsole): int {
            // Re-establish console context in the new coroutine if it was set
            // by Kernel::handle() (CLI entry point) before spawning.
            if ($shouldRunInConsole) {
                $this->app->markAsRunningInConsole();
            }

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

    protected function replaceOutput(): void
    {
        /* @phpstan-ignore-next-line */
        if ($this->app->bound(OutputInterface::class)) {
            $this->output = $this->app->get(OutputInterface::class);
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
