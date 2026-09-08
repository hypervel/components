<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Queue\Console\Concerns\ParsesQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\Worker;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:pause')]
class PauseCommand extends Command
{
    use ParsesQueue;

    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:pause
                            {queue? : The name of the queue to pause}
                            {--all : Pause job processing for all queues on all connections}';

    /**
     * The console command description.
     */
    protected string $description = 'Pause job processing for a specific queue';

    /**
     * Execute the console command.
     */
    public function handle(QueueFactory $manager): int
    {
        if (! Worker::$pausable) {
            $this->components->error('Queue pausing is currently disabled.');

            return self::FAILURE;
        }

        /** @var QueueManager $manager */
        if ($this->option('all')) {
            $manager->pauseAll();

            $this->components->info('Job processing on all queues across all connections has been paused.');

            return self::SUCCESS;
        }

        /** @var null|string $queue */
        $queue = $this->argument('queue');

        if ($queue === null || $queue === '') {
            $this->components->error('A queue name is required unless the --all option is used.');

            return self::FAILURE;
        }

        [$connection, $queue] = $this->parseQueue($queue);

        $manager->pause($connection, $queue);

        $this->components->info("Job processing on queue [{$connection}:{$queue}] has been paused.");

        return self::SUCCESS;
    }
}
