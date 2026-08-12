<?php

declare(strict_types=1);

return [
    'custom_option' => 'rate-limiter',

    'default' => 'overwrite',

    'stores' => [
        'database' => [
            'overwrite' => true,
        ],

        'new' => [
            'merge' => true,
        ],
    ],
];
