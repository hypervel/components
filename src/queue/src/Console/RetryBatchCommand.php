<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Command\Command;
use Hypervel\Bus\Contracts\BatchRepository;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class RetryBatchCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command signature.
     */
    protected ?string $signature = 'queue:retry-batch
                            {id?* : The ID of the batch whose failed jobs should be retried}';

    /**
     * The console command description.
     */
    protected string $description = 'Retry the failed jobs for a batch';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchesFound = count($ids = $this->getBatchJobIds()) > 0;

        if ($batchesFound) {
            $this->info('Pushing failed batch jobs back onto the queue.');
        }

        foreach ($ids as $batchId) {
            $batch = $this->app->get(BatchRepository::class)->find($batchId);

            if (! $batch) {
                $this->error("Unable to find a batch with ID [{$batchId}].");

                return 1;
            }
            if (empty($batch->failedJobIds)) {
                $this->error('The given batch does not contain any failed jobs.');

                return 1;
            }

            $this->info("Pushing failed queue jobs of the batch [{$batchId}] back onto the queue.");

            foreach ($batch->failedJobIds as $failedJobId) {
                $this->call('queue:retry', ['id' => $failedJobId]);
            }
        }

        return 0;
    }

    /**
     * Get the batch IDs to be retried.
     */
    protected function getBatchJobIds(): array
    {
        $ids = (array) $this->argument('id');

        return array_values(array_filter(array_unique($ids)));
    }
}
