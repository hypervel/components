<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Watcher\Driver\DriverInterface;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Watcher
{
    public function __construct(
        protected DriverInterface $driver,
        protected OutputInterface $output,
        protected ?RestartStrategy $strategy = null,
    ) {
    }

    /**
     * Start watching for file changes.
     */
    public function run(): void
    {
        $channel = new Channel(999);
        $driverFinished = new WaitGroup(1);
        $driverFailure = null;
        $driverStarted = false;
        $exception = null;
        $result = [];
        $capture = static function (callable $callback) use (&$exception): void {
            try {
                $callback();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        };

        try {
            $this->strategy?->start();

            Coroutine::create(function () use ($channel, $driverFinished, &$driverFailure): void {
                try {
                    $this->driver->watch($channel);
                } catch (Throwable $throwable) {
                    $driverFailure = $throwable;
                } finally {
                    $driverFinished->done();
                }
            });
            $driverStarted = true;

            while ($driverFinished->count() > 0) {
                $file = $channel->pop(0.001);

                if ($file === false) {
                    if ($result !== []) {
                        $result = [];
                        $this->strategy?->restart();
                    }

                    continue;
                }

                $this->output->writeln('<info>File changed:</info> ' . $file);
                $result[] = $file;
            }

            if ($driverFailure !== null) {
                throw $driverFailure;
            }

            while ($channel->getLength() > 0) {
                $file = $channel->pop();
                $this->output->writeln('<info>File changed:</info> ' . $file);
                $result[] = $file;
            }

            if ($result !== []) {
                $this->strategy?->restart();
            }
        } catch (Throwable $throwable) {
            $exception = $throwable;
        } finally {
            $capture(fn () => $this->driver->stop());
            $capture(fn () => $this->strategy?->stop());
            $capture(fn () => $channel->close());
            $capture(function () use ($driverStarted, $driverFinished): void {
                if ($driverStarted && ! $driverFinished->wait(1.0)) {
                    throw new RuntimeException('The file watcher did not stop within one second.');
                }
            });
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}
