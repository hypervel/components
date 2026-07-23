<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Support\DotenvManager;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ServerRestartStrategy implements RestartStrategy
{
    protected ?int $processId = null;

    protected bool $lifecycleRunning = false;

    protected bool $restartRequested = false;

    protected bool $stopping = false;

    protected string $bin;

    /** @var non-empty-list<non-empty-string> */
    protected array $command;

    public function __construct(
        protected Container $container,
        protected OutputInterface $output,
    ) {
        $config = $container->make('config');

        if ($config->boolean('server.settings.daemonize')) {
            throw new InvalidArgumentException('Please set `server.settings.daemonize` to false');
        }

        $bin = $config->string('watcher.bin');
        $command = $config->array('watcher.command');

        if ($bin === '') {
            throw new InvalidArgumentException('The watcher.bin configuration value must be a non-empty executable path.');
        }

        if (! array_is_list($command)
            || $command === []
            || array_any($command, fn (mixed $argument): bool => ! is_string($argument) || $argument === '')
        ) {
            throw new InvalidArgumentException('The watcher.command configuration value must be a non-empty list of non-empty strings.');
        }

        /** @var non-empty-list<non-empty-string> $command */
        $this->bin = $bin;
        $this->command = $command;
    }

    /**
     * Perform the initial start of the server process.
     */
    public function start(): void
    {
        if ($this->lifecycleRunning) {
            return;
        }

        $this->stopping = false;
        $this->restartRequested = false;
        $this->launchServer();
    }

    /**
     * Restart the server process (stop current instance, start new).
     */
    public function restart(): void
    {
        if ($this->stopping) {
            return;
        }

        if (! $this->lifecycleRunning) {
            $this->start();

            return;
        }

        $this->restartRequested = true;
        $this->terminateServer();
    }

    /**
     * Stop the currently running server process.
     */
    public function stop(): void
    {
        $this->stopping = true;
        $this->restartRequested = false;
        $this->terminateServer();
    }

    /**
     * Terminate the currently published server process.
     */
    protected function terminateServer(): void
    {
        try {
            $this->output->writeln('Stop server...');
        } catch (Throwable) {
        }

        // No yielding work may occur after this read: the PID belongs to the
        // exact unreaped child retained by the owner coroutine.
        $pid = $this->processId;

        if ($pid === null) {
            return;
        }

        try {
            $this->signalProcess($pid, SIGTERM);
        } catch (Throwable) {
            try {
                $this->output->writeln('<error>Stop server failed.</error>');
            } catch (Throwable) {
            }
        }
    }

    /**
     * Launch the server lifecycle in its owner coroutine.
     */
    protected function launchServer(): void
    {
        $this->lifecycleRunning = true;

        try {
            Coroutine::create(function (): void {
                try {
                    while (! $this->stopping) {
                        $this->runServer();

                        if (! $this->restartRequested) {
                            break;
                        }

                        $this->restartRequested = false;
                    }
                } finally {
                    $this->processId = null;
                    $this->restartRequested = false;
                    $this->lifecycleRunning = false;
                }
            });
        } catch (CoroutineCreateException $exception) {
            $this->lifecycleRunning = false;

            throw $exception;
        }
    }

    /**
     * Run one watched server process to completion.
     */
    protected function runServer(): void
    {
        $process = null;
        $exception = null;

        try {
            $this->reloadEnvironment();
            $this->output->writeln('Start server ...');

            $descriptorSpec = [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ];
            $pipes = [];

            $process = $this->openProcess($descriptorSpec, $pipes);

            if (! is_resource($process)) {
                throw new RuntimeException('Unable to launch the watched server process.');
            }

            $status = $this->processStatus($process);
            $pid = $status['pid'] ?? null;

            if (! is_int($pid) || $pid <= 0) {
                throw new RuntimeException('Unable to determine the watched server process ID.');
            }

            $this->processId = $pid;

            if ($this->stopping || $this->restartRequested) {
                $this->terminateServer();
            }

            $this->closeProcess($process);
            $this->processId = null;
            $process = null;
            $this->output->writeln('Server exited.');
        } catch (Throwable $throwable) {
            $exception = $throwable;
        } finally {
            $this->processId = null;

            if (is_resource($process)) {
                try {
                    $this->closeProcess($process);
                } catch (Throwable $throwable) {
                    $exception ??= $throwable;
                }
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Build the server command arguments without a shell intermediary.
     *
     * @return non-empty-list<non-empty-string>
     */
    protected function serverCommand(): array
    {
        $script = $this->command[0];
        $arguments = array_slice($this->command, 1);

        return [$this->bin, base_path($script), ...$arguments];
    }

    /**
     * Open the watched server process.
     *
     * @param array<int, resource> $descriptorSpec
     * @param array<int, resource> $pipes
     * @return false|resource
     */
    protected function openProcess(array $descriptorSpec, array &$pipes): mixed
    {
        return proc_open(
            command: $this->serverCommand(),
            descriptor_spec: $descriptorSpec,
            pipes: $pipes,
        );
    }

    /**
     * Close the watched server process.
     *
     * @param resource $process
     */
    protected function closeProcess(mixed $process): int
    {
        return proc_close($process);
    }

    /**
     * Read the watched server process status.
     *
     * @param resource $process
     * @return array<string, bool|int|string>
     */
    protected function processStatus(mixed $process): array
    {
        return proc_get_status($process);
    }

    /**
     * Send a signal to a server process.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }

    /**
     * Reload the application environment before spawning a server.
     */
    protected function reloadEnvironment(): void
    {
        $environmentFile = $this->container
            ->make(ApplicationContract::class)
            ->environmentFilePath();

        DotenvManager::reload([dirname($environmentFile)], basename($environmentFile));
    }
}
