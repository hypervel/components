<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console\Concerns;

use Carbon\CarbonInterface;
use Hypervel\Support\Str;
use Hypervel\Telescope\EntryResult;
use Hypervel\Telescope\EntryType;
use Symfony\Component\Console\Output\OutputInterface;

trait FormatsOutput
{
    /**
     * Verify the given entry types are valid, printing an error if not.
     */
    protected function ensureValidEntryTypes(string ...$types): bool
    {
        $invalid = collect($types)->diff(EntryType::all());

        if ($invalid->isEmpty()) {
            return true;
        }

        $this->error('Invalid entry type: ' . $invalid->implode(', '));
        $this->line('Valid types: ' . implode(', ', EntryType::all()));

        return false;
    }

    /**
     * Append a unit to the given value, or return an empty string if it has none.
     */
    protected function unit(int|float|string|null $value, string $unit): string
    {
        return $value === null || $value === '' ? '' : $value . $unit;
    }

    /**
     * Get a shortened UUID for display.
     */
    protected function shortUuid(string $uuid): string
    {
        return substr($uuid, 0, 8);
    }

    /**
     * Format a timestamp as a human readable difference.
     */
    protected function humanTime(CarbonInterface $date): string
    {
        return $date->diffForHumans();
    }

    /**
     * Generate a one-line summary for the given entry.
     */
    protected function summarizeEntry(EntryResult $entry): string
    {
        $content = $entry->content;

        return match ($entry->type) {
            EntryType::REQUEST => ($content['method'] ?? '') . ' ' . ($content['uri'] ?? '') . ' -> ' . ($content['response_status'] ?? '') . ' (' . ($content['duration'] ?? '') . 'ms)',
            EntryType::EXCEPTION => Str::limit(($content['class'] ?? '') . ': ' . ($content['message'] ?? ''), 80),
            EntryType::QUERY => Str::limit($content['sql'] ?? '', 60) . ' (' . ($content['time'] ?? '') . 'ms)',
            EntryType::JOB => class_basename($content['name'] ?? '') . ' [' . ($content['status'] ?? '') . ']',
            EntryType::CACHE => ($content['type'] ?? '') . ' ' . ($content['key'] ?? ''),
            EntryType::LOG => '[' . ($content['level'] ?? '') . '] ' . Str::limit($content['message'] ?? '', 60),
            EntryType::MAIL => Str::limit($content['subject'] ?? $content['mailable'] ?? '', 80),
            EntryType::EVENT => ($content['name'] ?? '') . (isset($content['listeners']) ? ' (' . count($content['listeners']) . ' listeners)' : ''),
            EntryType::MODEL => ($content['model'] ?? '') . ': ' . ($content['action'] ?? ''),
            EntryType::GATE => ($content['ability'] ?? '') . ': ' . ($content['result'] ?? ''),
            EntryType::VIEW => $content['name'] ?? '',
            EntryType::NOTIFICATION => class_basename($content['notification'] ?? '') . ' via ' . ($content['channel'] ?? ''),
            EntryType::REDIS => Str::limit($content['command'] ?? '', 60),
            EntryType::CLIENT_REQUEST => ($content['method'] ?? '') . ' ' . Str::limit($content['uri'] ?? '', 40) . ' -> ' . ($content['response_status'] ?? 'N/A'),
            EntryType::COMMAND => $content['command'] ?? '',
            EntryType::SCHEDULED_TASK => ($content['command'] ?? '') . ' [' . ($content['expression'] ?? '') . ']',
            EntryType::DUMP => Str::limit(trim(strip_tags($content['dump'] ?? '')), 80),
            default => Str::limit(json_encode($content, JSON_THROW_ON_ERROR), 80),
        };
    }

    /**
     * Colorize an HTTP method for console output.
     */
    protected function colorMethod(string $method): string
    {
        return match (strtoupper($method)) {
            'GET' => "<fg=gray>{$method}</>",
            'POST', 'PATCH', 'PUT' => "<fg=blue>{$method}</>",
            'DELETE' => "<fg=red>{$method}</>",
            default => $method,
        };
    }

    /**
     * Colorize an HTTP status code for console output.
     */
    protected function colorStatus(int $status): string
    {
        return match (true) {
            $status < 300 => "<fg=green>{$status}</>",
            $status < 400 => "<fg=blue>{$status}</>",
            $status < 500 => "<fg=yellow>{$status}</>",
            default => "<fg=red>{$status}</>",
        };
    }

    /**
     * Colorize a log level for console output.
     */
    protected function colorLevel(string $level): string
    {
        return match ($level) {
            'emergency', 'alert', 'critical', 'error' => "<fg=red>{$level}</>",
            'warning' => "<fg=yellow>{$level}</>",
            'notice', 'info' => "<fg=blue>{$level}</>",
            'debug' => "<fg=gray>{$level}</>",
            default => $level,
        };
    }

    /**
     * Colorize a cache action for console output.
     */
    protected function colorCacheAction(string $type): string
    {
        return match ($type) {
            'hit' => '<fg=green>HIT</>',
            'missed' => '<fg=red>MISS</>',
            'set' => '<fg=blue>SET</>',
            'forget' => '<fg=yellow>FORGET</>',
            default => $type,
        };
    }

    /**
     * Colorize a job status for console output.
     */
    protected function colorJobStatus(string $status): string
    {
        return match ($status) {
            'processed' => "<fg=green>{$status}</>",
            'failed' => "<fg=red>{$status}</>",
            'pending' => "<fg=yellow>{$status}</>",
            default => $status,
        };
    }

    /**
     * Format a value as a pretty-printed JSON block.
     */
    protected function jsonBlock(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Write the given data as JSON output.
     */
    protected function writeJson(mixed $data): void
    {
        // Formatting would strip console style tags, and backslashes before < or >, from recorded values.
        $this->output->writeln($this->jsonBlock($data), OutputInterface::OUTPUT_RAW);
    }
}
