<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Watcher\Events\BeforeServerRestart;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ServerRestartStrategy implements RestartStrategy
{
    protected Channel $channel;

    protected Filesystem $filesystem;

    protected string $pidFile;

    protected string $bin;

    /** @var non-empty-list<non-empty-string> */
    protected array $command;

    public function __construct(
        protected Container $container,
        protected OutputInterface $output,
    ) {
        $config = $container->make('config');

        $pidFile = $config->string('server.settings.pid_file', '');
        if (empty($pidFile)) {
            throw new FileNotFoundException('The config of pid_file is not found.');
        }

        if ($config->boolean('server.settings.daemonize', false)) {
            throw new InvalidArgumentException('Please set `server.settings.daemonize` to false');
        }

        $bin = $config->string('watcher.bin', PHP_BINARY);
        $command = $config->array('watcher.command', ['artisan', 'serve']);

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
        $this->pidFile = $pidFile;
        $this->bin = $bin;
        $this->command = $command;
        $this->filesystem = new Filesystem;
        $this->channel = new Channel(1);
        $this->channel->push(true);
    }

    /**
     * Perform the initial start of the server process.
     */
    public function start(): void
    {
        $this->launchServer();
    }

    /**
     * Restart the server process (stop current instance, start new).
     */
    public function restart(): void
    {
        $this->stop();
        $this->launchServer();
    }

    /**
     * Stop the currently running server process.
     */
    public function stop(): void
    {
        try {
            $pidContents = trim($this->filesystem->get($this->pidFile));
        } catch (FileNotFoundException) {
            return;
        }

        if ($pidContents === '' || ! ctype_digit($pidContents)) {
            return;
        }

        $pid = (int) $pidContents;

        if ($pid <= 0) {
            return;
        }

        try {
            $this->output->writeln('Stop server...');
            /** @var Dispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(BeforeServerRestart::class)) {
                $events->dispatch(new BeforeServerRestart($pid));
            }

            if ($this->signalProcess($pid, 0)) {
                $this->signalProcess($pid, SIGTERM);
            }
        } catch (Throwable) {
            $this->output->writeln('<error>Stop server failed.</error>');
        }
    }

    /**
     * Launch the server process in a coroutine with channel-based coordination.
     */
    protected function launchServer(): void
    {
        Coroutine::create(function (): void {
            $this->channel->pop();

            try {
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

                $this->closeProcess($process);
                $this->output->writeln('Server exited.');
            } finally {
                $this->channel->push(1);
            }
        });
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
     * Send a signal to a server process.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}
