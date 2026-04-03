<?php

declare(strict_types=1);

return [
    'custom_option' => 'database',

    'default' => 'overwrite',

    'connections' => [
        'mysql' => [
            'overwrite' => true,
        ],

        'new' => [
            'merge' => true,
        ],
    ],
];
