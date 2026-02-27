<?php

declare(strict_types=1);

/**
 * Base listeners config - tests pure list arrays.
 *
 * This is the CRITICAL test case for the priority listener bug.
 * The override will add listeners with priority (string keys with int values).
 */
return [
    'App\Listeners\EventLoggerListener',
    'App\Listeners\AuditListener',
    'App\Listeners\RegisterCommandListener',
];
