<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Console\Command;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Telescope\Console\Concerns\FormatsOutput;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\EntryResult;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryQueryOptions;
use Hypervel\Telescope\Telescope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'telescope:show')]
class ShowCommand extends Command
{
    use FormatsOutput;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:show
        {id : Entry UUID, "latest", or "latest:{type}" (e.g. latest:exception)}
        {--type= : Filter batch entries to specific type(s), comma-separated}
        {--full : Do not truncate SQL, messages, or payloads}
        {--json : Output the entry and its batch as JSON}';

    /**
     * The console command description.
     */
    protected string $description = 'Show a Telescope entry with full batch context';

    /**
     * Execute the console command.
     */
    public function handle(EntriesRepository $storage): int
    {
        return Telescope::withoutRecording(function () use ($storage): int {
            $entry = $this->findEntry($storage, $this->argument('id'));

            if (! $entry) {
                return 1;
            }

            $batchId = $entry->content['updated_batch_id'] ?? $entry->batchId;

            $types = Str::of($this->option('type'))->explode(',')->map(fn (string $type): string => trim($type))->filter();

            if (! $this->ensureValidEntryTypes(...$types)) {
                return 1;
            }

            $batchEntries = $batchId
                ? collect($storage->get(null, EntryQueryOptions::forBatchId($batchId)->limit(-1)))->reverse()->values()
                : collect();

            $batchEntries = $batchEntries->reject(fn (EntryResult $other): bool => $other->id === $entry->id)
                ->when($types->isNotEmpty(), fn (Collection $entries): Collection => $entries->whereIn('type', $types))
                ->values();

            if ($this->option('json')) {
                $this->writeJson(['entry' => $entry, 'batch' => $batchEntries->all()]);

                return 0;
            }

            $this->renderEntry($entry);
            $this->renderBatchContext($batchId, $batchEntries->groupBy('type'), $types);

            return 0;
        });
    }

    /**
     * Find the entry with the given ID, supporting the "latest" and "latest:{type}" shortcuts.
     */
    protected function findEntry(EntriesRepository $storage, string $id): ?EntryResult
    {
        if ($id === 'latest' || str_starts_with($id, 'latest:')) {
            $type = $id === 'latest' ? null : Str::after($id, 'latest:');

            if ($type && ! $this->ensureValidEntryTypes($type)) {
                return null;
            }

            $entry = collect($storage->get($type, (new EntryQueryOptions)->limit(1)))->first();

            if (! $entry) {
                $this->error('No ' . ($type ? "{$type} " : '') . 'entries found.');
            }

            return $entry;
        }

        try {
            return $storage->find($id);
        } catch (ModelNotFoundException) {
            $this->error("Entry not found: {$id}");

            return null;
        }
    }

    /**
     * Render the full detail view for the given entry.
     */
    protected function renderEntry(EntryResult $entry): void
    {
        match ($entry->type) {
            EntryType::REQUEST => $this->renderRequest($entry),
            EntryType::EXCEPTION => $this->renderException($entry),
            EntryType::JOB => $this->renderJob($entry),
            default => $this->renderGenericEntry($entry),
        };
    }

    /**
     * Render a request entry.
     */
    protected function renderRequest(EntryResult $entry): void
    {
        $content = $entry->content;

        $this->info('Request: ' . ($content['method'] ?? '') . ' ' . OutputFormatter::escape($content['uri'] ?? '') . ' -> ' . ($content['response_status'] ?? ''));

        $this->details($entry, [
            'Controller' => $content['controller_action'] ?? 'Closure',
            'Duration' => $this->unit($content['duration'] ?? null, 'ms'),
            'Memory' => $this->unit($content['memory'] ?? null, 'MB'),
            'IP' => $content['ip_address'] ?? '',
            'Middleware' => implode(', ', (array) ($content['middleware'] ?? [])),
            'User' => OutputFormatter::escape($this->formatUser($content['user'] ?? [])),
        ]);

        $this->block('Payload', $content['payload'] ?? null);
        $this->block('Response', $content['response'] ?? null, 1000);
    }

    /**
     * Render an exception entry with code context and stack trace.
     */
    protected function renderException(EntryResult $entry): void
    {
        $content = $entry->content;

        $this->info('Exception: ' . class_basename($content['class'] ?? ''));

        $this->details($entry, [
            'Class' => $content['class'] ?? '',
            'File' => ($content['file'] ?? '') . ':' . ($content['line'] ?? ''),
            'Occurrences' => (string) ($content['occurrences'] ?? 1),
            'Resolved' => $content['resolved_at'] ?? '<fg=yellow>No</>',
        ]);

        $this->error(OutputFormatter::escape($content['message'] ?? ''));

        if (! empty($content['line_preview'])) {
            $this->info('Code Context');

            $this->table([], collect($content['line_preview'])->map(fn (string $code, int $lineNo): array => [
                $lineNo === ($content['line'] ?? 0) ? "<fg=red>{$lineNo} ></>" : $lineNo,
                OutputFormatter::escape($code),
            ])->values());
        }

        $this->renderTrace($content['trace'] ?? []);
    }

    /**
     * Render a job entry with exception detail if failed.
     */
    protected function renderJob(EntryResult $entry): void
    {
        $content = $entry->content;

        $this->info('Job: ' . class_basename($content['name'] ?? '') . ' [' . ($content['status'] ?? 'pending') . ']');

        $this->details($entry, [
            'Status' => $this->colorJobStatus($content['status'] ?? 'pending'),
            'Queue' => $content['queue'] ?? '',
            'Connection' => $content['connection'] ?? '',
            'Tries' => (string) ($content['tries'] ?? ''),
            'Timeout' => $this->unit($content['timeout'] ?? null, 's'),
        ]);

        $this->block('Data', $content['data'] ?? null, 500);

        if (! empty($content['exception'])) {
            $this->error(OutputFormatter::escape($content['exception']['message'] ?? ''));
            $this->renderTrace($content['exception']['trace'] ?? [], 10);
        }
    }

    /**
     * Render any entry type using a data-driven field map.
     */
    protected function renderGenericEntry(EntryResult $entry): void
    {
        $content = $entry->content;
        $config = $this->entryFieldConfig($entry->type, $content) + [
            'label' => ucfirst($entry->type), 'subtitle' => '', 'fields' => [], 'list' => null, 'blocks' => [],
        ];

        $this->info($config['subtitle'] === ''
            ? $config['label']
            : "{$config['label']}: " . OutputFormatter::escape($config['subtitle']));

        $this->details($entry, $config['fields']);

        if (! empty($config['list'])) {
            $this->listing($config['list']['label'], $config['list']['items']);
        }

        foreach ($config['blocks'] as $label => $key) {
            $this->block($label, $key === null ? $content : ($content[$key] ?? null), $key === null ? 1000 : 500);
        }
    }

    /**
     * Get the field configuration for a given entry type.
     */
    protected function entryFieldConfig(string $type, array $content): array
    {
        return match ($type) {
            EntryType::QUERY => [
                'label' => 'Query',
                'fields' => [
                    'Connection' => $content['connection'] ?? '',
                    'Duration' => $this->unit($content['time'] ?? null, 'ms') . (! empty($content['slow']) ? '  <fg=red>SLOW</>' : ''),
                    'Source' => isset($content['file']) ? ($content['file']) . ':' . ($content['line'] ?? '') : '',
                    'SQL' => OutputFormatter::escape($content['sql'] ?? ''),
                ],
                'blocks' => ['Bindings' => 'bindings'],
            ],
            EntryType::CACHE => [
                'label' => 'Cache', 'subtitle' => $content['type'] ?? '',
                'fields' => [
                    'Key' => OutputFormatter::escape($content['key'] ?? ''),
                    'Expiration' => $this->unit($content['expiration'] ?? null, 's'),
                ],
                'blocks' => ['Value' => 'value'],
            ],
            EntryType::LOG => [
                'label' => 'Log', 'subtitle' => $content['level'] ?? '',
                'fields' => ['Message' => OutputFormatter::escape($content['message'] ?? '')],
                'blocks' => ['Context' => 'context'],
            ],
            EntryType::MAIL => [
                'label' => 'Mail', 'subtitle' => $content['subject'] ?? '',
                'fields' => [
                    'Mailable' => $content['mailable'] ?? '', 'Subject' => OutputFormatter::escape($content['subject'] ?? ''),
                    'Queued' => ! empty($content['queued']) ? 'Yes' : '',
                    'To' => OutputFormatter::escape($this->formatAddresses($content['to'] ?? [])),
                    'From' => OutputFormatter::escape($this->formatAddresses($content['from'] ?? [])),
                ],
            ],
            EntryType::EVENT => [
                'label' => 'Event', 'subtitle' => $content['name'] ?? '',
                'fields' => ['Broadcast' => ! empty($content['broadcast']) ? 'Yes' : ''],
                'list' => ! empty($content['listeners']) ? ['label' => 'Listeners', 'items' => collect($content['listeners'])->map(fn (array $listener): string => $listener['name'] . (empty($listener['queued']) ? '' : ' (queued)'))->all()] : null,
                'blocks' => ['Payload' => 'payload'],
            ],
            EntryType::COMMAND => [
                'label' => 'Command', 'subtitle' => $content['command'] ?? '',
                'fields' => ['Exit Code' => (string) ($content['exit_code'] ?? '')],
                'blocks' => ['Arguments' => 'arguments', 'Options' => 'options'],
            ],
            EntryType::SCHEDULED_TASK => [
                'label' => 'Scheduled Task', 'subtitle' => $content['command'] ?? '',
                'fields' => [
                    'Expression' => $content['expression'] ?? '', 'Timezone' => $content['timezone'] ?? '',
                    'Description' => OutputFormatter::escape($content['description'] ?? ''),
                    'Status' => $content['status'] ?? '', 'Exit Code' => (string) ($content['exit_code'] ?? ''),
                ],
                'blocks' => ['Exception' => 'exception', 'Output' => 'output'],
            ],
            EntryType::CLIENT_REQUEST => [
                'label' => 'Client Request', 'subtitle' => ($content['method'] ?? '') . ' ' . ($content['uri'] ?? ''),
                'fields' => [
                    'Status' => isset($content['response_status']) ? $this->colorStatus((int) $content['response_status']) : 'N/A',
                    'Duration' => $this->unit($content['duration'] ?? null, 'ms'),
                ],
                'blocks' => ['Payload' => 'payload', 'Response' => 'response'],
            ],
            EntryType::GATE => [
                'label' => 'Gate',
                'subtitle' => ($content['ability'] ?? '') . ': ' . ($content['result'] ?? ''),
                'fields' => ['Source' => isset($content['file']) ? $content['file'] . ':' . ($content['line'] ?? '') : ''],
                'blocks' => ['Arguments' => 'arguments'],
            ],
            EntryType::MODEL => [
                'label' => 'Model', 'subtitle' => ($content['model'] ?? '') . ': ' . ($content['action'] ?? ''),
                'fields' => ['Count' => isset($content['count']) ? (string) $content['count'] : ''],
                'blocks' => ['Changes' => 'changes'],
            ],
            EntryType::NOTIFICATION => [
                'label' => 'Notification', 'subtitle' => class_basename($content['notification'] ?? ''),
                'fields' => [
                    'Channel' => $content['channel'] ?? '', 'Notifiable' => $content['notifiable'] ?? '',
                    'Queued' => ! empty($content['queued']) ? 'Yes' : '',
                ],
            ],
            EntryType::VIEW => [
                'label' => 'View', 'subtitle' => $content['name'] ?? '',
                'fields' => ['Path' => $content['path'] ?? ''],
                'list' => ! empty($content['composers']) ? [
                    'label' => 'Composers',
                    'items' => collect($content['composers'])->map(fn (array $composer): string => ($composer['name'] ?? '') . (isset($composer['type']) ? " ({$composer['type']})" : ''))->all(),
                ] : null,
                'blocks' => ['Data' => 'data'],
            ],
            EntryType::REDIS => [
                'label' => 'Redis',
                'fields' => [
                    'Connection' => $content['connection'] ?? '',
                    'Duration' => $this->unit($content['time'] ?? null, 'ms'),
                    'Command' => OutputFormatter::escape($content['command'] ?? ''),
                ],
            ],
            default => [
                'label' => ucfirst($type),
                'blocks' => ['Content' => null],
            ],
        };
    }

    /**
     * Render the batch context for the given entry.
     */
    protected function renderBatchContext(string $batchId, Collection $batchByType, Collection $requestedTypes): void
    {
        if ($batchByType->isEmpty()) {
            if ($requestedTypes->isNotEmpty()) {
                $this->line('No batch entries of type ' . $requestedTypes->implode(', ') . '.');
            }

            return;
        }

        $this->info('Related Entries - batch ' . $this->shortUuid($batchId));

        $detailed = [EntryType::QUERY, EntryType::EXCEPTION, EntryType::CACHE, EntryType::LOG];

        $this->renderBatchQueries($batchByType->get(EntryType::QUERY, collect()));
        $this->renderBatchExceptions($batchByType->get(EntryType::EXCEPTION, collect()));
        $this->renderBatchCache($batchByType->get(EntryType::CACHE, collect()));
        $this->renderBatchLogs($batchByType->get(EntryType::LOG, collect()));

        foreach ($batchByType->except($detailed) as $type => $entries) {
            $label = Str::plural(Str::headline($type));

            $this->listing("{$label} ({$entries->count()})", $entries->take(5)->map(fn (EntryResult $related): string => $this->summarizeEntry($related))->all());
            $this->more($entries->count(), 5, $label);
        }
    }

    /**
     * Render the batch query section with stats.
     */
    protected function renderBatchQueries(Collection $queries): void
    {
        if ($queries->isEmpty()) {
            return;
        }

        $totalTime = round($queries->sum(fn (EntryResult $query): float => (float) ($query->content['time'] ?? 0)), 2);
        $slowCount = $queries->filter(fn (EntryResult $query): bool => ! empty($query->content['slow']))->count();

        $duplicates = $queries->groupBy(fn (EntryResult $query): string => $query->content['hash'] ?? $query->content['sql'] ?? '')
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        $this->info(
            "Queries - {$queries->count()} total, {$totalTime}ms"
            . ($slowCount > 0 ? ", {$slowCount} slow" : '')
            . ($duplicates->isNotEmpty() ? ', ' . $duplicates->count() . ' duplicate ' . Str::plural('group', $duplicates->count()) : '')
        );

        $this->table(
            ['#', 'UUID', 'Time', 'SQL', 'Source', 'Flags'],
            $queries->take(20)->values()->map(fn (EntryResult $query, int $index): array => [
                $index + 1,
                $this->shortUuid($query->id),
                round((float) ($query->content['time'] ?? 0), 2) . 'ms',
                OutputFormatter::escape($this->limit($query->content['sql'] ?? '', 60)),
                isset($query->content['file']) ? basename($query->content['file']) . ':' . ($query->content['line'] ?? '') : '',
                trim(
                    (! empty($query->content['slow']) ? '<fg=red>SLOW</> ' : '')
                    . ($duplicates->has($query->content['hash'] ?? $query->content['sql'] ?? '') ? '<fg=yellow>DUP</>' : '')
                ),
            ])
        );

        $this->more($queries->count(), 20, 'queries');
    }

    /**
     * Render the batch exception section.
     */
    protected function renderBatchExceptions(Collection $exceptions): void
    {
        if ($exceptions->isEmpty()) {
            return;
        }

        $this->info("Exceptions - {$exceptions->count()}");

        $this->table(
            ['UUID', 'Exception', 'Location'],
            $exceptions->take(10)->map(fn (EntryResult $exception): array => [
                $this->shortUuid($exception->id),
                OutputFormatter::escape(($exception->content['class'] ?? '') . ': ' . $this->limit($exception->content['message'] ?? '', 80)),
                ($exception->content['file'] ?? '') . ':' . ($exception->content['line'] ?? ''),
            ])
        );

        $this->more($exceptions->count(), 10, 'exceptions');
    }

    /**
     * Render the batch cache section with hit rate.
     */
    protected function renderBatchCache(Collection $cacheEntries): void
    {
        if ($cacheEntries->isEmpty()) {
            return;
        }

        $hits = $cacheEntries->filter(fn (EntryResult $cacheEntry): bool => ($cacheEntry->content['type'] ?? '') === 'hit')->count();
        $misses = $cacheEntries->filter(fn (EntryResult $cacheEntry): bool => ($cacheEntry->content['type'] ?? '') === 'missed')->count();
        $lookups = $hits + $misses;

        $this->info("Cache - {$hits} hits, {$misses} misses"
            . ($lookups > 0 ? ' - ' . round($hits / $lookups * 100, 1) . '% hit rate' : ''));

        $this->table(
            ['Action', 'Key'],
            $cacheEntries->take(10)->map(fn (EntryResult $cacheEntry): array => [
                $this->colorCacheAction($cacheEntry->content['type'] ?? ''),
                OutputFormatter::escape($this->limit($cacheEntry->content['key'] ?? '', 60)),
            ])
        );

        $this->more($cacheEntries->count(), 10, 'cache entries');
    }

    /**
     * Render the batch log section.
     */
    protected function renderBatchLogs(Collection $logs): void
    {
        if ($logs->isEmpty()) {
            return;
        }

        $this->info("Logs - {$logs->count()}");

        $this->table(
            ['Level', 'Message'],
            $logs->take(10)->map(fn (EntryResult $log): array => [
                $this->colorLevel($log->content['level'] ?? ''),
                OutputFormatter::escape($this->limit($log->content['message'] ?? '', 80)),
            ])
        );

        $this->more($logs->count(), 10, 'logs');
    }

    /**
     * Render the entry's common fields followed by the given fields as a key/value table.
     *
     * Field values may contain console styles, so callers escape recorded text.
     *
     * @param array<string, null|string> $fields
     */
    protected function details(EntryResult $entry, array $fields): void
    {
        $rows = collect([
            'Time' => $this->humanTime($entry->createdAt) . "  <fg=gray>({$entry->createdAt})</>",
            'Hostname' => $entry->content['hostname'] ?? '',
        ] + $fields)
            ->filter(fn (?string $value): bool => $value !== '' && $value !== null)
            ->map(fn (string $value, string $label): array => [$label, $value])
            ->values();

        $this->table([], $rows);
    }

    /**
     * Render a titled single-column list of literal text items.
     */
    protected function listing(string $title, array $items): void
    {
        $this->info($title);

        $this->table([], collect($items)->map(fn (string $item): array => [OutputFormatter::escape($item)]));
    }

    /**
     * Render a titled content block (JSON or plain text) if it has a value.
     */
    protected function block(string $label, mixed $value, int $limit = 500): void
    {
        if (empty($value)) {
            return;
        }

        $this->info($label);

        // Formatting would strip console style tags, and backslashes before < or >, from recorded content.
        $this->output->writeln($this->limit(is_string($value) ? $value : $this->jsonBlock($value), $limit), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Truncate the given value unless the --full option was given.
     */
    protected function limit(string $value, int $limit): string
    {
        return $this->option('full') ? $value : Str::limit($value, $limit);
    }

    /**
     * Note how many items were omitted beyond the given limit.
     */
    protected function more(int $count, int $limit, string $label): void
    {
        if ($count > $limit) {
            $this->line('... and ' . ($count - $limit) . " more {$label}");
        }
    }

    /**
     * Render a stack trace.
     */
    protected function renderTrace(array $trace, int $limit = 15): void
    {
        if (empty($trace)) {
            return;
        }

        $this->listing('Stack Trace', collect($trace)->take($limit)->map(fn (array $frame): string => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?'))->all());

        $this->more(count($trace), $limit, 'frames');
    }

    /**
     * Format the authenticated user for display.
     */
    protected function formatUser(array $user): string
    {
        return implode(' ', array_filter([
            $user['name'] ?? null,
            isset($user['email']) ? "({$user['email']})" : null,
            isset($user['id']) ? "#{$user['id']}" : null,
        ]));
    }

    /**
     * Format an array of email addresses for display.
     */
    protected function formatAddresses(array $addresses): string
    {
        return collect($addresses)->map(fn (?string $name, string $email): string => $name ? "{$name} <{$email}>" : $email)->implode(', ');
    }
}
