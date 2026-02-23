<?php

declare(strict_types=1);

/**
 * Override listeners config - THE CRITICAL TEST CASE.
 *
 * This tests mixed arrays where:
 * - Numeric keys are listeners to append
 * - String keys are listeners with priority values
 *
 * The bug we fixed: Arr::merge was treating this as a list and
 * losing the string keys, leaving just the priority value (99).
 */
return [
    // Regular listeners (numeric keys - should be appended)
    'App\Listeners\ModelEventListener',
    'Hypervel\ServerProcess\Listeners\BootProcessListener',

    // Priority listeners (string keys - MUST be preserved)
    'App\Listeners\ModelHookEventListener' => 99,
    'Hyperf\Signal\Listener\SignalRegisterListener' => PHP_INT_MAX,
];
