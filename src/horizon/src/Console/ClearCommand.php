<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:clear')]
class ClearCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'horizon:clear
                            {connection? : The name of the queue connection}
                            {--queue= : The name of the queue to clear}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete all of the jobs from the specified queue';

    /**
     * Execute the console command.
     */
    public function handle(JobRepository $jobRepository, QueueManager $manager): ?int
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        $connection = $this->argument('connection');

        if ($connection === null || $connection === '') {
            $connection = array_first(config()->array('horizon.defaults', []))['connection'] ?? 'redis';
        }

        $queue = $this->getQueue($connection);
        $queueConnection = $manager->connection($connection);

        if (! $queueConnection instanceof ClearableQueue) {
            $this->components->error('Clearing queues is not supported on [' . (new ReflectionClass($queueConnection))->getShortName() . ']');

            return 1;
        }

        if ($queueConnection instanceof RedisQueue) {
            // Horizon records the forwarded destination; clear still needs the original
            // queue name so the destination is not forwarded a second time.
            $jobRepository->purge(
                Str::replaceFirst('queues:', '', $queueConnection->getQueue($queue)),
                $queueConnection->getConnectionName(),
            );
        }

        $count = $queueConnection->clear($queue);

        $this->components->info('Cleared ' . $count . ' jobs from the [' . $queue . '] queue.');

        return 0;
    }

    /**
     * Get the queue name to clear.
     */
    protected function getQueue(string $connection): string
    {
        $queue = $this->option('queue');

        return $queue === null || $queue === ''
            ? config("queue.connections.{$connection}.queue", 'default')
            : $queue;
    }
}
