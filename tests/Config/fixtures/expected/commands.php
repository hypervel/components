<?php

declare(strict_types=1);

/**
 * Expected merged commands config.
 *
 * Verifies:
 * - All commands from both configs are present
 * - CacheCommand appears only once (deduplicated)
 */
return [
    'App\Commands\MigrateCommand',
    'App\Commands\SeedCommand',
    'App\Commands\CacheCommand',  // From base, duplicate in override is skipped
    'App\Commands\QueueCommand',
    'App\Commands\ScheduleCommand',
];
