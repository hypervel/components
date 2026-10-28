<?php

declare(strict_types=1);

return [
    'exclude' => [],
    'stubs' => [
        'app.php' => [
            'omit' => ['providers', 'aliases'],
        ],
    ],
    'skeletons' => [
        'hypervel' => [],
        'react-starter-kit' => [],
    ],
];
