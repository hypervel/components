<?php

declare(strict_types=1);

/**
 * Override commands config - tests list deduplication.
 *
 * CacheCommand appears in both base and override - should only appear once.
 */
return [
    'App\Commands\CacheCommand',  // Duplicate - should be deduplicated
    'App\Commands\QueueCommand',
    'App\Commands\ScheduleCommand',
];
