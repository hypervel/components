<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Arr;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\RedisQueue;

class ClearCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     */
    protected string $signature = 'horizon:clear
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

        if (! method_exists(RedisQueue::class, 'clear')) {
            $this->components->error('Clearing queues is not supported on this version of Laravel.');

            return 1;
        }

        $connection = $this->argument('connection')
            ?: Arr::first($this->laravel['config']->get('horizon.defaults'))['connection'] ?? 'redis';

        if (method_exists($jobRepository, 'purge')) {
            $jobRepository->purge($queue = $this->getQueue($connection));
        }

        $count = $manager->connection($connection)->clear($queue);

        $this->components->info('Cleared '.$count.' jobs from the ['.$queue.'] queue.');

        return 0;
    }

    /**
     * Get the queue name to clear.
     */
    protected function getQueue(string $connection): string
    {
        return $this->option('queue') ?: $this->laravel['config']->get(
            "queue.connections.{$connection}.queue",
            'default'
        );
    }
}
