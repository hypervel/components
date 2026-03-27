<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Queue\Console\Concerns\ParsesQueue;
use Hypervel\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:pause')]
class PauseCommand extends Command
{
    use ParsesQueue;

    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:pause {queue : The name of the queue to pause}';

    /**
     * The console command description.
     */
    protected string $description = 'Pause job processing for a specific queue';

    /**
     * Execute the console command.
     */
    public function handle(QueueFactory $manager): int
    {
        [$connection, $queue] = $this->parseQueue($this->argument('queue'));

        /** @var QueueManager $manager */
        $manager->pause($connection, $queue);

        $this->components->info("Job processing on queue [{$connection}:{$queue}] has been paused.");

        return 0;
    }
}
