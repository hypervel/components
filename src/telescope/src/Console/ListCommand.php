<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Closure;
use Hypervel\Console\Command;
use Hypervel\Support\Str;
use Hypervel\Telescope\Console\Concerns\FormatsOutput;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\EntryResult;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryQueryOptions;
use Hypervel\Telescope\Telescope;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'telescope:list')]
class ListCommand extends Command
{
    use FormatsOutput;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:list
        {type? : Entry type (omit or pass an invalid type to list the valid ones)}
        {--tag= : Filter by tag}
        {--batch= : Filter by batch ID}
        {--family= : Filter by family hash}
        {--limit=20 : Max entries to show}
        {--before= : Pagination cursor (sequence ID)}
        {--json : Output entries as JSON}';

    /**
     * The console command description.
     */
    protected string $description = 'List Telescope entries';

    /**
     * Execute the console command.
     */
    public function handle(EntriesRepository $storage): int
    {
        return Telescope::withoutRecording(function () use ($storage): int {
            $type = $this->argument('type');

            if ($type && ! $this->ensureValidEntryTypes($type)) {
                return 1;
            }

            if (! ctype_digit((string) $this->option('limit')) || ($limit = (int) $this->option('limit')) < 1) {
                $this->error('The --limit option must be a positive integer.');

                return 1;
            }

            $entries = collect($storage->get($type, $this->queryOptions($limit)));

            if ($this->option('json')) {
                $this->line($this->jsonBlock($entries->all()));

                return 0;
            }

            if ($entries->isEmpty()) {
                $this->warn('No entries found.');

                return 0;
            }

            [$headers, $row] = $this->tableColumns($type);

            $this->table($headers, $entries->map($row)->all());

            $nextPage = $entries->count() >= $limit
                ? "Use --before={$entries->last()->sequence} for next page"
                : 'No more entries';

            $this->info('Showing ' . $entries->count() . ' ' . Str::plural('entry', $entries->count()) . " - {$nextPage}");

            return 0;
        });
    }

    /**
     * Build the entry query options from the command's options.
     */
    protected function queryOptions(int $limit): EntryQueryOptions
    {
        return (new EntryQueryOptions)
            ->tag($this->option('tag'))
            ->batchId($this->option('batch'))
            ->familyHash($this->option('family'))
            ->beforeSequence($this->option('before'))
            ->limit($limit);
    }

    /**
     * Get the table headers and row formatter for the given entry type.
     *
     * @return array{0: list<string>, 1: Closure(EntryResult): array}
     */
    protected function tableColumns(?string $type): array
    {
        return match ($type) {
            EntryType::REQUEST => [
                ['UUID', 'Method', 'URI', 'Status', 'Duration', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    $this->colorMethod($entry->content['method'] ?? ''),
                    Str::limit($entry->content['uri'] ?? '', 40),
                    $this->colorStatus((int) ($entry->content['response_status'] ?? 0)),
                    $this->unit($entry->content['duration'] ?? null, 'ms'),
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::QUERY => [
                ['UUID', 'SQL', 'Time', 'Slow', 'Connection', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    Str::limit($entry->content['sql'] ?? '', 60),
                    $this->unit($entry->content['time'] ?? null, 'ms'),
                    ! empty($entry->content['slow']) ? '<fg=red>Yes</>' : 'No',
                    $entry->content['connection'] ?? '',
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::EXCEPTION => [
                ['UUID', 'Class', 'Message', 'Occurrences', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    class_basename($entry->content['class'] ?? ''),
                    Str::limit($entry->content['message'] ?? '', 50),
                    $entry->content['occurrences'] ?? 1,
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::JOB => [
                ['UUID', 'Name', 'Queue', 'Status', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    class_basename($entry->content['name'] ?? ''),
                    $entry->content['queue'] ?? '',
                    $this->colorJobStatus($entry->content['status'] ?? ''),
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::CACHE => [
                ['UUID', 'Action', 'Key', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    $this->colorCacheAction($entry->content['type'] ?? ''),
                    Str::limit($entry->content['key'] ?? '', 50),
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::LOG => [
                ['UUID', 'Level', 'Message', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    $this->colorLevel($entry->content['level'] ?? ''),
                    Str::limit($entry->content['message'] ?? '', 60),
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::MAIL => [
                ['UUID', 'Mailable', 'Subject', 'To', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    class_basename($entry->content['mailable'] ?? ''),
                    Str::limit($entry->content['subject'] ?? '', 40),
                    Str::limit(implode(', ', array_keys($entry->content['to'] ?? [])), 30),
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::COMMAND => [
                ['UUID', 'Command', 'Exit Code', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    Str::limit($entry->content['command'] ?? '', 50),
                    $entry->content['exit_code'] ?? '',
                    $this->humanTime($entry->createdAt),
                ],
            ],
            EntryType::CLIENT_REQUEST => [
                ['UUID', 'Method', 'URI', 'Status', 'Duration', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    $this->colorMethod($entry->content['method'] ?? ''),
                    Str::limit($entry->content['uri'] ?? '', 40),
                    isset($entry->content['response_status']) ? $this->colorStatus((int) $entry->content['response_status']) : 'N/A',
                    $this->unit($entry->content['duration'] ?? null, 'ms'),
                    $this->humanTime($entry->createdAt),
                ],
            ],
            default => [
                ['UUID', 'Type', 'Summary', 'Created'],
                fn (EntryResult $entry): array => [
                    $this->shortUuid($entry->id),
                    $entry->type,
                    $this->summarizeEntry($entry),
                    $this->humanTime($entry->createdAt),
                ],
            ],
        };
    }
}
