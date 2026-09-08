<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Queue\Console\Concerns\ParsesQueue;
use Hypervel\Queue\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:resume', aliases: ['queue:continue'])]
class ResumeCommand extends Command
{
    use ParsesQueue;

    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:resume
                            {queue? : The name of the queue that should resume processing}
                            {--all : Resume job processing for all queues on all connections}';

    /**
     * The console command name aliases.
     *
     * @var list<string>
     */
    protected array $aliases = ['queue:continue'];

    /**
     * The console command description.
     */
    protected string $description = 'Resume job processing for a paused queue';

    /**
     * Execute the console command.
     */
    public function handle(QueueFactory $manager): int
    {
        /** @var QueueManager $manager */
        if ($this->option('all')) {
            $manager->resumeAll();

            $this->components->info('Job processing on all queues across all connections has been resumed.');

            return self::SUCCESS;
        }

        /** @var null|string $queue */
        $queue = $this->argument('queue');

        if ($queue === null || $queue === '') {
            $this->components->error('A queue name is required unless the --all option is used.');

            return self::FAILURE;
        }

        [$connection, $queue] = $this->parseQueue($queue);

        $manager->resume($connection, $queue);

        $this->components->info("Job processing on queue [{$connection}:{$queue}] has been resumed.");

        return self::SUCCESS;
    }
}
