<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Watcher\Driver\DriverInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        $this->strategy?->start();

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
                    $this->strategy?->restart();
                }
            } else {
                $this->output->writeln('<info>File changed:</info> ' . $file);
                $result[] = $file;
            }
        }
    }
}
