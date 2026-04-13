<?php

declare(strict_types=1);

return [
    'custom_option' => 'queue',

    'default' => 'overwrite',

    'connections' => [
        'database' => [
            'overwrite' => true,
        ],

        'new' => [
            'merge' => true,
        ],
    ],
];
