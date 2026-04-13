<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Watcher\Events\BeforeServerRestart;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ServerRestartStrategy implements RestartStrategy
{
    protected Channel $channel;

    protected Filesystem $filesystem;

    protected string $pidFile;

    protected string $bin;

    protected string $command;

    public function __construct(
        protected Container $container,
        protected OutputInterface $output,
    ) {
        $config = $container->make('config');

        $pidFile = $config->get('server.settings.pid_file');
        if (empty($pidFile)) {
            throw new FileNotFoundException('The config of pid_file is not found.');
        }

        if ($config->get('server.settings.daemonize', false)) {
            throw new InvalidArgumentException('Please set `server.settings.daemonize` to false');
        }

        $this->pidFile = $pidFile;
        $this->bin = $config->get('watcher.bin', PHP_BINARY);
        $this->command = $config->get('watcher.command', 'artisan serve');
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
        $this->stopServer();
        $this->launchServer();
    }

    /**
     * Stop the currently running server process.
     */
    protected function stopServer(): void
    {
        if (! $this->filesystem->exists($this->pidFile)) {
            return;
        }

        $pid = $this->filesystem->get($this->pidFile);

        try {
            $this->output->writeln('Stop server...');
            $this->container->make('events')
                ->dispatch(new BeforeServerRestart($pid));
            if (posix_kill((int) $pid, 0)) {
                posix_kill((int) $pid, SIGTERM);
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
        Coroutine::create(function () {
            $this->channel->pop();
            $this->output->writeln('Start server ...');

            $descriptorSpec = [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ];

            $process = proc_open(
                command: $this->getBin() . ' ' . base_path($this->command),
                descriptor_spec: $descriptorSpec,
                pipes: $pipes
            );

            if (is_resource($process)) {
                proc_close($process);
            }

            $this->output->writeln('Server exited.');
            $this->channel->push(1);
        });
    }

    /**
     * Get the PHP binary path, quoted if it contains spaces.
     */
    protected function getBin(): string
    {
        if (str_contains($this->bin, ' ')) {
            return '"' . $this->bin . '"';
        }

        return $this->bin;
    }
}
