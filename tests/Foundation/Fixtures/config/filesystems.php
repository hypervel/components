<?php

declare(strict_types=1);

return [
    'custom_option' => 'filesystems',

    'default' => 'overwrite',

    'disks' => [
        'local' => [
            'overwrite' => true,
        ],

        'new' => [
            'merge' => true,
        ],
    ],
];
