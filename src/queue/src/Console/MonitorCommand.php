<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Config\Repository;
use Hypervel\Console\Command;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Queue\Events\QueueBusy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:monitor')]
class MonitorCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'queue:monitor
                       {queues : The names of the queues to monitor}
                       {--max=1000 : The maximum number of jobs that can be on the queue before an event is dispatched}
                       {--json : Output the queue size as JSON}';

    /**
     * The console command description.
     */
    protected string $description = 'Monitor the size of the specified queues';

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
    public function handle(): void
    {
        $queues = $this->parseQueues((string) $this->argument('queues'));

        if ($this->option('json')) {
            $this->output->writeln($queues->map(fn (array $queue): array => array_merge($queue, [
                'status' => str_contains($queue['status'], 'ALERT') ? 'ALERT' : 'OK',
            ]))->toJson());
        } else {
            $this->displaySizes($queues);
        }

        $this->dispatchEvents($queues);
    }

    /**
     * Parse the queues into an array of the connections and queues.
     *
     * @return Collection<int, array{
     *     connection: string,
     *     queue: string,
     *     size: int,
     *     pending: int,
     *     delayed: int,
     *     reserved: int,
     *     oldest_pending: null|int,
     *     status: string
     * }>
     */
    protected function parseQueues(string $queues): Collection
    {
        return Collection::make(explode(',', $queues))->map(function (string $queue): array {
            [$connection, $queue] = array_pad(explode(':', $queue, 2), 2, null);

            if (! isset($queue)) {
                $queue = $connection;
                $connection = $this->config->string('queue.default');
            }

            $queueConnection = $this->manager->connection($connection);

            return [
                'connection' => $connection,
                'queue' => $queue,
                'size' => $size = $queueConnection->size($queue),
                'pending' => $queueConnection->pendingSize($queue),
                'delayed' => $queueConnection->delayedSize($queue),
                'reserved' => $queueConnection->reservedSize($queue),
                'oldest_pending' => $queueConnection->creationTimeOfOldestPendingJob($queue),
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
        $this->newLine();

        $this->components->twoColumnDetail('<fg=gray>Queue name</>', '<fg=gray>Size / Status</>');

        $queues->each(function (array $queue): void {
            $name = '[' . $queue['connection'] . '] ' . $queue['queue'];
            $status = '[' . $queue['size'] . '] ' . $queue['status'];

            $this->components->twoColumnDetail($name, $status);
            $this->components->twoColumnDetail('Pending jobs', (string) $queue['pending']);
            $this->components->twoColumnDetail('Delayed jobs', (string) $queue['delayed']);
            $this->components->twoColumnDetail('Reserved jobs', (string) $queue['reserved']);
            $this->components->twoColumnDetail(
                'Oldest pending job',
                $queue['oldest_pending'] !== null
                    ? CarbonImmutable::createFromTimestamp($queue['oldest_pending'])->diffForHumans()
                    : 'N/A',
            );
            $this->line('');
        });

        $this->newLine();
    }

    /**
     * Fire the monitoring events.
     */
    protected function dispatchEvents(Collection $queues): void
    {
        foreach ($queues as $queue) {
            if ($queue['status'] === '<fg=green;options=bold>OK</>') {
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
