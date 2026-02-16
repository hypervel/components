<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Queue\Events\QueueBusy;
use Hypervel\Support\Collection;

class MonitorCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:monitor
                       {queues : The names of the queues to monitor}
                       {--max=1000 : The maximum number of jobs that can be on the queue before an event is dispatched}';

    /**
     * The console command description.
     */
    protected string $description = 'Monitor the size of the specified queues';

    /**
     * The table headers for the command.
     */
    protected array $headers = ['Connection', 'Queue', 'Size', 'Status'];

    /**
     * Create a new queue monitor command.
     */
    public function __construct(
        protected Factory $manager,
        protected Dispatcher $events,
        protected Repository $config,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queues = $this->parseQueues($this->argument('queues'));

        $this->displaySizes($queues);

        $this->dispatchEvents($queues);
    }

    /**
     * Parse the queues into an array of the connections and queues.
     * @param mixed $queues
     */
    protected function parseQueues($queues): Collection
    {
        return Collection::make(explode(',', $queues))->map(function ($queue) {
            [$connection, $queue] = array_pad(explode(':', $queue, 2), 2, null);

            if (! isset($queue)) {
                $queue = $connection;
                $connection = $this->config->get('queue.default');
            }

            return [
                'connection' => $connection,
                'queue' => $queue,
                'size' => $size = $this->manager->connection($connection)->size($queue),
                'pending' => $this->manager->connection($connection)->pendingSize($queue),
                'delayed' => $this->manager->connection($connection)->delayedSize($queue),
                'reserved' => $this->manager->connection($connection)->reservedSize($queue),
                'oldest_pending' => $this->manager->connection($connection)->creationTimeOfOldestPendingJob($queue),
                'status' => $size >= (int) $this->option('max')
                    ? '<fg=yellow;options=bold>ALERT</>'
                    : '<fg=green;options=bold>OK</>',
            ];
        });
    }

    /**
     * Display the queue sizes in the console.
     */
    protected function displaySizes(Collection $queues): void
    {
        $this->table($this->headers, $queues->toArray());
    }

    /**
     * Fire the monitoring events.
     */
    protected function dispatchEvents(Collection $queues): void
    {
        foreach ($queues as $queue) {
            if ($queue['status'] == '<fg=green;options=bold>OK</>') {
                continue;
            }

            $this->events->dispatch(
                new QueueBusy(
                    $queue['connection'],
                    $queue['queue'],
                    $queue['size'],
                )
            );
        }
    }
}
