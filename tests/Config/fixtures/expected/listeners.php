<?php

declare(strict_types=1);

/**
 * Expected merged listeners config - THE CRITICAL TEST.
 *
 * Verifies:
 * - All numeric-keyed listeners from both configs are present
 * - Priority listeners have their STRING KEYS preserved (not lost!)
 * - Priority values (99, PHP_INT_MAX) are correct
 * - No stray priority values appearing as list items
 */
return [
    // From base (numeric keys)
    0 => 'App\Listeners\EventLoggerListener',
    1 => 'App\Listeners\AuditListener',
    2 => 'Hyperf\Command\Listener\RegisterCommandListener',

    // From override (numeric keys - appended)
    3 => 'App\Listeners\ModelEventListener',
    4 => 'Hypervel\ServerProcess\Listeners\BootProcessListener',

    // From override (string keys with priority - MUST be preserved)
    'App\Listeners\ModelHookEventListener' => 99,
    'Hyperf\Signal\Listener\SignalRegisterListener' => PHP_INT_MAX,
];
