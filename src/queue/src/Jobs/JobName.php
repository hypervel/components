<?php

declare(strict_types=1);

namespace Hypervel\Queue\Jobs;

use Hypervel\Support\Str;

class JobName
{
    /**
     * Parse the given job name into a class / method array.
     */
    public static function parse(string $job): array
    {
        return Str::parseCallback($job, 'fire');
    }

    /**
     * Determine whether the payload carries an object job.
     */
    public static function hasJobObject(array $payload): bool
    {
        // Check the marker before caller-owned data, which may still be an object in payload hooks.
        // Unmarked payloads retain Laravel's format, including custom object-job handlers.
        return ! isset($payload['stringJob']) && isset($payload['data']['command']);
    }

    /**
     * Get the resolved name of the queued job class.
     */
    public static function resolve(string $name, array $payload): string
    {
        if (! empty($payload['displayName'])) {
            return $payload['displayName'];
        }

        return $name;
    }

    /**
     * Get the class name for queued job class.
     *
     * @param array<string, mixed> $payload
     */
    public static function resolveClassName(string $name, array $payload): string
    {
        if (is_string($payload['data']['commandName'] ?? null)) {
            return $payload['data']['commandName'];
        }

        return $name;
    }
}
