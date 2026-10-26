<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Queue\Console\Concerns\FindsOutboxMessages;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:outbox-retry')]
class RetryOutboxCommand extends Command
{
    use FindsOutboxMessages;

    protected ?string $signature = 'queue:outbox-retry
                            {uuid?* : The job UUIDs, or "all" to retry every attempted message without a live lease}
                            {--database= : The configured outbox database containing the messages}';

    protected string $description = 'Make attempted outbox messages available for delivery';

    /**
     * Create a new outbox retry command.
     */
    public function __construct(protected DatabaseOutboxRepository $repository)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $uuids = array_values(array_unique($this->argument('uuid')));
        $database = $this->option('database');

        if ($uuids === ['all']) {
            $count = 0;

            foreach ($database === null ? $this->repository->getDatabases() : [$database] as $source) {
                $count += $this->repository->retryAll($source);
            }

            $this->components->info("Made {$count} outbox messages available for retry.");

            return self::SUCCESS;
        }

        if ($uuids === [] || in_array('all', $uuids, true)) {
            throw new InvalidArgumentException('Specify one or more job UUIDs, or "all" by itself.');
        }

        $status = self::SUCCESS;

        foreach ($uuids as $uuid) {
            $found = $this->findOutboxMessage($uuid, $database);

            if ($found === null) {
                $this->components->error("No outbox message matches UUID [{$uuid}].");
                $status = self::FAILURE;
            } elseif ($this->repository->retry($found['database'], $uuid) === 0) {
                $this->components->error("Outbox message [{$uuid}] has not been attempted or is currently leased.");
                $status = self::FAILURE;
            } else {
                $this->components->info("Outbox message [{$uuid}] is available for retry.");
            }
        }

        return $status;
    }
}
