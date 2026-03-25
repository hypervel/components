<?php

declare(strict_types=1);

return [
    'custom_option' => 'broadcasting',

    'default' => 'overwrite',

    'connections' => [
        'reverb' => [
            'overwrite' => true,
        ],

        'new' => [
            'merge' => true,
        ],
    ],
];
