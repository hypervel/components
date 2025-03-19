<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Command\Command;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Support\Traits\HasLaravelStyleCommand;

class ListFailedCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command signature.
     */
    protected ?string $signature = 'queue:failed';

    /**
     * The console command description.
     */
    protected string $description = 'List all of the failed queue jobs';

    /**
     * The table headers for the command.
     */
    protected array $headers = ['ID', 'Connection', 'Queue', 'Class', 'Failed At'];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (count($jobs = $this->getFailedJobs()) === 0) {
            return $this->info('No failed jobs found.');
        }

        $this->newLine();
        $this->displayFailedJobs($jobs);
        $this->newLine();
    }

    /**
     * Compile the failed jobs into a displayable format.
     */
    protected function getFailedJobs(): array
    {
        $failed = $this->app->get(FailedJobProviderInterface::class)->all();

        return Collection::make($failed)->map(function ($failed) {
            return $this->parseFailedJob((array) $failed);
        })->filter()->all();
    }

    /**
     * Parse the failed job row.
     */
    protected function parseFailedJob(array $failed): array
    {
        $row = array_values(Arr::except($failed, ['payload', 'exception']));

        array_splice($row, 3, 0, $this->extractJobName($failed['payload']) ?: '');

        return $row;
    }

    /**
     * Extract the failed job name from payload.
     */
    private function extractJobName(string $payload): ?string
    {
        $payload = json_decode($payload, true);

        if ($payload && (! isset($payload['data']['command']))) {
            return $payload['job'] ?? null;
        }
        if ($payload && isset($payload['data']['command'])) {
            return $this->matchJobName($payload);
        }

        return null;
    }

    /**
     * Match the job name from the payload.
     */
    protected function matchJobName(array $payload): ?string
    {
        preg_match('/"([^"]+)"/', $payload['data']['command'], $matches);

        return $matches[1] ?? $payload['job'] ?? null;
    }

    /**
     * Display the failed jobs in the console.
     */
    protected function displayFailedJobs(array $jobs): void
    {
        $this->table($this->headers, $jobs);
    }
}
