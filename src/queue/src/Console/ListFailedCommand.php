<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\Command;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'queue:failed')]
class ListFailedCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'queue:failed
                            {--json : Output the failed jobs as JSON}';

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
    public function handle(): void
    {
        $jobs = $this->getFailedJobs();

        if ($this->option('json')) {
            $this->displayFailedJobsAsJson($jobs);

            return;
        }

        if (count($jobs) === 0) {
            $this->components->info('No failed jobs found.');

            return;
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
        $failed = $this->hypervel->make(FailedJobProviderInterface::class)->all();

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

        if (! is_array($payload)) {
            return null;
        }

        if (! isset($payload['data']['command'])) {
            return is_string($payload['job'] ?? null) ? $payload['job'] : null;
        }

        if (! empty($payload['displayName']) && is_string($payload['displayName'])) {
            return $payload['displayName'];
        }

        return $this->matchJobName($payload);
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
        Collection::make($jobs)->each(
            fn (array $job) => $this->components->twoColumnDetail(
                sprintf('<fg=gray>%s</> %s</>', $job[4], $job[0]),
                sprintf('<fg=gray>%s@%s</> %s', $job[1], $job[2], $job[3])
            ),
        );
    }

    /**
     * Display the failed jobs as JSON.
     */
    protected function displayFailedJobsAsJson(array $jobs): void
    {
        $this->output->writeln(Collection::make($jobs)->values()->map(fn (array $job): array => [
            'id' => $job[0],
            'connection' => $job[1],
            'queue' => $job[2],
            'class' => $job[3],
            'failed_at' => $job[4],
        ])->toJson());
    }
}
