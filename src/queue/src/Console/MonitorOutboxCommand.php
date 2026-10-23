<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Queue\Events\OutboxBusy;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:outbox-monitor')]
class MonitorOutboxCommand extends Command
{
    protected ?string $signature = 'queue:outbox-monitor
                            {database? : The configured outbox database to monitor}
                            {--max= : Alert when the total pending count reaches this limit}
                            {--age= : Alert when the oldest eligible message reaches this age in seconds}
                            {--json : Output outbox statistics as JSON}';

    protected string $description = 'Monitor pending transactional outbox messages';

    /**
     * Create a new outbox monitor command.
     */
    public function __construct(
        protected DatabaseOutboxRepository $repository,
        protected Dispatcher $events,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $maximum = $this->option('max') === null ? null : (int) $this->option('max');
        $age = $this->option('age') === null ? null : (int) $this->option('age');

        if (($maximum !== null && $maximum < 0) || ($age !== null && $age < 0)) {
            throw new InvalidArgumentException('Outbox monitoring thresholds must be non-negative.');
        }

        $database = $this->argument('database');
        $rows = [];

        foreach ($database === null ? $this->repository->getDatabases() : [$database] as $source) {
            $statistics = $this->repository->statistics($source);
            $alert = ($maximum !== null && $statistics['total'] >= $maximum)
                || ($age !== null && $statistics['oldest_eligible_age'] !== null && $statistics['oldest_eligible_age'] >= $age);
            $rows[] = ['database' => $source, ...$statistics, 'status' => $alert ? 'ALERT' : 'OK'];

            if ($alert && $this->events->hasListeners(OutboxBusy::class)) {
                $this->events->dispatch(new OutboxBusy($source, $statistics['total'], $statistics['eligible'], $statistics['oldest_eligible_age']));
            }
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode($rows, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->table(['Database', 'Total', 'Eligible', 'Delayed', 'Leased', 'Retrying', 'Oldest Eligible (s)', 'Status'], $rows);
        }
    }
}
