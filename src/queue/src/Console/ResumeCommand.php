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
    protected ?string $signature = 'queue:resume {queue : The name of the queue that should resume processing}';

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
        [$connection, $queue] = $this->parseQueue($this->argument('queue'));

        /** @var QueueManager $manager */
        $manager->resume($connection, $queue);

        $this->components->info("Job processing on queue [{$connection}:{$queue}] has been resumed.");

        return 0;
    }
}
