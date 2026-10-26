<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:outbox')]
class ListOutboxCommand extends Command
{
    use InteractsWithTime;

    protected ?string $signature = 'queue:outbox
                            {database? : The configured outbox database to inspect}
                            {--limit=100 : The maximum number of messages to show per database}
                            {--json : Output pending messages as JSON}';

    protected string $description = 'List pending transactional outbox messages';

    /**
     * Create a new outbox listing command.
     */
    public function __construct(protected DatabaseOutboxRepository $repository)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The outbox listing limit must be between 1 and 1000.');
        }

        $database = $this->argument('database');
        $rows = [];
        $now = $this->currentTime();

        foreach ($database === null ? $this->repository->getDatabases() : [$database] as $source) {
            foreach ($this->repository->pending($source, $limit) as $message) {
                $payload = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);
                $rows[] = [
                    'database' => $source,
                    'id' => (int) $message->id,
                    'uuid' => $message->uuid,
                    'job' => $payload['displayName'] ?? $payload['job'] ?? '',
                    'connection' => $message->connection,
                    'queue' => $message->queue,
                    'attempts' => (int) $message->attempts,
                    'state' => match (true) {
                        $message->lease_token !== null && (int) $message->available_at > $now => 'leased',
                        (int) $message->available_at <= $now => 'eligible',
                        (int) $message->attempts > 0 => 'retrying',
                        default => 'delayed',
                    },
                    'age' => max(0, $now - (int) $message->created_at),
                    'available_at' => (int) $message->available_at,
                    'last_error' => $message->last_error,
                ];
            }
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode($rows, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } elseif ($rows === []) {
            $this->components->info('No pending outbox messages found.');
        } else {
            $rows = array_map(static function (array $row): array {
                $row['available_at'] = Date::createFromTimestampUTC($row['available_at'])->toDateTimeString();
                $row['last_error'] = Str::limit(Str::before($row['last_error'] ?? '', "\n"), 80);

                return $row;
            }, $rows);

            $this->table(['Database', 'ID', 'UUID', 'Job', 'Connection', 'Queue', 'Attempts', 'State', 'Age (s)', 'Available At (UTC)', 'Last Error'], $rows);
        }
    }
}
