<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Database;

use Hypervel\Console\Command;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Testbench\Contracts\TestCase;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\artisan;

/**
 * @internal
 */
class MigrateProcessor
{
    protected int $beforeBatch = 0;

    protected int $afterBatch = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected readonly TestCase $testbench,
        protected readonly Migrator $migrator,
        protected readonly array $options = [],
    ) {
    }

    /**
     * Run migration.
     */
    public function up(): static
    {
        /** @var null|string $connection */
        $connection = $this->options['--database'] ?? null;
        $repository = $this->migrator->getRepository();

        $this->migrator->usingConnection($connection, function () use ($repository): void {
            $this->beforeBatch = $repository->repositoryExists()
                ? $repository->getNextBatchNumber() - 1
                : 0;

            try {
                if ($this->dispatch('migrate') !== Command::SUCCESS) {
                    throw new RuntimeException('Unable to run migrations.');
                }
            } finally {
                $this->afterBatch = $repository->repositoryExists()
                    ? $repository->getNextBatchNumber() - 1
                    : $this->beforeBatch;
            }
        });

        return $this;
    }

    /**
     * Rollback migration.
     */
    public function rollback(): static
    {
        if ($this->afterBatch <= $this->beforeBatch) {
            return $this;
        }

        /** @var null|string $connection */
        $connection = $this->options['--database'] ?? null;
        $repository = $this->migrator->getRepository();
        $options = $this->options;
        unset(
            $options['--graceful'],
            $options['--schema-path'],
            $options['--seed'],
            $options['--seeder'],
            $options['--step'],
        );
        $failure = null;

        $this->migrator->usingConnection($connection, function () use ($repository, $options, &$failure): void {
            for ($batch = $this->afterBatch; $batch > $this->beforeBatch; --$batch) {
                try {
                    $options['--batch'] = $batch;

                    if ($this->dispatch('migrate:rollback', $options) !== Command::SUCCESS) {
                        throw new RuntimeException("Unable to roll back migration batch [{$batch}].");
                    }

                    if ($repository->getMigrationsByBatch($batch) !== []) {
                        throw new RuntimeException("Migration batch [{$batch}] remains after rollback.");
                    }
                } catch (Throwable $throwable) {
                    $failure ??= $throwable;
                }
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $this;
    }

    /**
     * Dispatch artisan command.
     *
     * @param null|array<string, mixed> $options
     */
    protected function dispatch(string $command, ?array $options = null): int
    {
        return artisan($this->testbench, $command, $options ?? $this->options);
    }
}
