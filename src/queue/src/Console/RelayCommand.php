<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Queue\Outbox\OutboxWorker;
use Hypervel\Queue\Outbox\OutboxWorkerOptions;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:relay')]
class RelayCommand extends Command
{
    protected ?string $signature = 'queue:relay
                            {database? : The configured outbox database to relay}
                            {--once : Relay one batch from each selected database}
                            {--stop-when-empty : Stop after a complete pass finds no eligible messages}
                            {--batch=100 : The maximum number of messages to claim at once}
                            {--sleep=1 : The number of seconds to wait after an empty pass or failure}
                            {--concurrency=1 : The maximum number of batches to relay at once}
                            {--max-jobs=0 : The number of messages to claim before stopping}
                            {--max-time=0 : The maximum number of seconds the relay should run}
                            {--memory=128 : The memory limit in megabytes, or as a percentage of the PHP memory limit}';

    protected string $description = 'Relay committed outbox messages to their queues';

    /**
     * Create a new queue relay command.
     */
    public function __construct(protected OutboxWorker $worker)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $database = $this->argument('database');

        // Avoid Symfony's stty subprocess probe inside this coroutine.
        if (defined('STDIN') && stream_isatty(STDIN)) {
            $this->components->info($database === null
                ? 'Relaying outbox messages from all configured databases.'
                : "Relaying outbox messages from [{$database}].");
        }

        return $this->worker->run($database, new OutboxWorkerOptions(
            batch: (int) $this->option('batch'),
            sleep: (float) $this->option('sleep'),
            concurrency: (int) $this->option('concurrency'),
            maxJobs: (int) $this->option('max-jobs'),
            maxTime: (int) $this->option('max-time'),
            memory: (string) $this->option('memory'),
            stopWhenEmpty: (bool) $this->option('stop-when-empty'),
            once: (bool) $this->option('once'),
        ));
    }
}
