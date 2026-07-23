<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\PhpBinary;
use Hypervel\Support\DotenvManager;
use Hypervel\Watcher\RestartStrategy;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class HorizonRestartStrategy implements RestartStrategy
{
    protected ?Process $horizonProcess = null;

    public function __construct(
        protected ApplicationContract $application,
        protected OutputInterface $output,
        protected ?string $environment = null,
    ) {
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            $this->stop();
            exit(0);
        });

        pcntl_signal(SIGTERM, function () {
            $this->stop();
            exit(0);
        });
    }

    /**
     * Perform the initial start of the Horizon process.
     */
    public function start(): void
    {
        $environmentFile = $this->application->environmentFilePath();
        DotenvManager::reload([dirname($environmentFile)], basename($environmentFile));

        $this->horizonProcess = $this->createProcess();
        $this->horizonProcess->start(function (string $type, string $line) {
            $this->output->write($line);
        });

        usleep(100_000);

        if ($this->horizonProcess->isTerminated()) {
            throw new RuntimeException('Horizon failed to start.');
        }
    }

    /**
     * Restart the Horizon process.
     */
    public function restart(): void
    {
        $this->output->writeln('<info>File changed. Restarting Horizon...</info>');

        $this->stop();
        $this->start();
    }

    /**
     * Stop the currently running Horizon process.
     */
    public function stop(): void
    {
        if ($this->horizonProcess?->isRunning()) {
            $this->horizonProcess->stop();
        }
    }

    /**
     * Create the Horizon child process.
     */
    protected function createProcess(): Process
    {
        $command = [PhpBinary::path(), 'artisan', 'horizon'];

        if ($this->environment) {
            $command[] = '--environment=' . $this->environment;
        }

        return (new Process($command))->setTimeout(null);
    }
}
