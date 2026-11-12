<?php

declare(strict_types=1);

return [
    'key' => 'package',
    'stores' => [
        'declared' => ['driver' => 'array'],
    ],
    'env' => env('PACKAGE_ENV'),
    'url' => config('app.url'),
];
