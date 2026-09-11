<?php

declare(strict_types=1);

/*
 * This file records command names, aliases, arguments, and options. It exists
 * to catch regressions if a command's public signature (name, argument, or option
 * definitions) accidentally changes - most notably during a refactor that
 * consolidates a command's $name/getArguments()/getOptions() into a single
 * $signature string.
 *
 * Update this file whenever a command's signature intentionally changes.
 */
return [
    \Hypervel\Queue\Console\PauseCommand::class => [
        'name' => 'queue:pause',
        'arguments' => [
            [
                'name' => 'queue',
                'mode' => 'optional',
                'isArray' => false,
                'default' => null,
                'description' => 'The name of the queue to pause',
            ],
        ],
        'options' => [
            [
                'name' => 'all',
                'shortcut' => null,
                'negatable' => false,
                'valueRequired' => false,
                'valueOptional' => false,
                'isArray' => false,
                'acceptValue' => false,
                'default' => false,
                'description' => 'Pause job processing for all queues on all connections',
            ],
            [
                'name' => 'disable-event-dispatcher',
                'shortcut' => null,
                'negatable' => false,
                'valueRequired' => false,
                'valueOptional' => false,
                'isArray' => false,
                'acceptValue' => false,
                'default' => false,
                'description' => 'Disable the event dispatcher',
            ],
        ],
    ],
    \Hypervel\Queue\Console\ResumeCommand::class => [
        'name' => 'queue:resume',
        'aliases' => [
            'queue:continue',
        ],
        'arguments' => [
            [
                'name' => 'queue',
                'mode' => 'optional',
                'isArray' => false,
                'default' => null,
                'description' => 'The name of the queue that should resume processing',
            ],
        ],
        'options' => [
            [
                'name' => 'all',
                'shortcut' => null,
                'negatable' => false,
                'valueRequired' => false,
                'valueOptional' => false,
                'isArray' => false,
                'acceptValue' => false,
                'default' => false,
                'description' => 'Resume job processing for all queues on all connections',
            ],
            [
                'name' => 'disable-event-dispatcher',
                'shortcut' => null,
                'negatable' => false,
                'valueRequired' => false,
                'valueOptional' => false,
                'isArray' => false,
                'acceptValue' => false,
                'default' => false,
                'description' => 'Disable the event dispatcher',
            ],
        ],
    ],
];
