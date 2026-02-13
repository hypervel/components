<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Console;

use Hypervel\Console\Command;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Queue\Failed\FailedJobProviderInterface;

class ForgetFailedCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'horizon:forget {id? : The ID of the failed job} {--all : Delete all failed jobs}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete a failed queue job';

    /**
     * Execute the console command.
     */
    public function handle(JobRepository $repository): ?int
    {
        if ($this->option('all')) {
            $totalFailedCount = $repository->totalFailed();

            do {
                $failedJobs = collect($repository->getFailed());

                $failedJobs->pluck('id')->each(function (string $failedId) use ($repository): void { // @phpstan-ignore argument.type
                    $repository->deleteFailed($failedId);

                    if ($this->app->make(FailedJobProviderInterface::class)->forget($failedId)) {
                        $this->components->info('Failed job (id): ' . $failedId . ' deleted successfully!');
                    }
                });
            } while ($repository->totalFailed() !== 0 && $failedJobs->isNotEmpty());

            if ($totalFailedCount) {
                $this->components->info($totalFailedCount . ' failed jobs deleted successfully!');
            } else {
                $this->components->info('No failed jobs detected.');
            }

            return null;
        }

        if (! $this->argument('id')) {
            $this->components->error('No failed job ID provided.');
        }

        $repository->deleteFailed($this->argument('id'));

        if ($this->app->make(FailedJobProviderInterface::class)->forget($this->argument('id'))) {
            $this->components->info('Failed job deleted successfully!');
        } else {
            $this->components->error('No failed job matches the given ID.');

            return 1;
        }

        return 0;
    }
}
