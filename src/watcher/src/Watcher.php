<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Watcher\Driver\DriverInterface;
use Hypervel\Watcher\Events\BeforeServerRestart;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Watcher
{
    protected DriverInterface $driver;

    protected Filesystem $filesystem;

    protected ConfigContract $config;

    protected Channel $channel;

    public function __construct(
        protected Container $container,
        protected Option $option,
        protected OutputInterface $output,
    ) {
        $this->driver = $this->getDriver();
        $this->filesystem = new Filesystem();
        $this->config = $container->make('config');
        $this->channel = new Channel(1);
        $this->channel->push(true);
    }

    /**
     * Start watching for file changes.
     */
    public function run(): void
    {
        $this->restart(true);

        $channel = new Channel(999);
        Coroutine::create(function () use ($channel) {
            $this->driver->watch($channel);
        });

        $result = [];
        while (true) { /** @phpstan-ignore while.alwaysTrue */
            $file = $channel->pop(0.001);
            if ($file === false) {
                if (count($result) > 0) {
                    $result = [];
                    $this->restart(false);
                }
            } else {
                $this->output->writeln('<info>File changed:</info> ' . $file);
                $result[] = $file;
            }
        }
    }

    /**
     * Restart the server process.
     */
    public function restart(bool $isStart = true): void
    {
        if (! $this->option->isRestart()) {
            return;
        }
        $file = $this->config->get('server.settings.pid_file');
        if (empty($file)) {
            throw new FileNotFoundException('The config of pid_file is not found.');
        }
        $daemonize = $this->config->get('server.settings.daemonize', false);
        if ($daemonize) {
            throw new InvalidArgumentException('Please set `server.settings.daemonize` to false');
        }
        if (! $isStart && $this->filesystem->exists($file)) {
            $pid = $this->filesystem->get($file);
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

        Coroutine::create(function () {
            $this->channel->pop();
            $this->output->writeln('Start server ...');

            $descriptorSpec = [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ];

            $process = proc_open(
                command: $this->option->getBin() . ' ' . BASE_PATH . '/' . $this->option->getCommand(),
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
     * Resolve the file watcher driver.
     */
    protected function getDriver(): DriverInterface
    {
        $driver = $this->option->getDriver();
        if (! class_exists($driver)) {
            throw new InvalidArgumentException('Driver not support.');
        }
        return $this->container->make($driver, ['option' => $this->option]);
    }
}
