<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Diagnostic Selection
    |--------------------------------------------------------------------------
    |
    | These selectors use the same syntax as the --only and --except command
    | options: diagnostic class names, groups, Composer packages, or package
    | wildcards. Leave them empty to run every registered diagnostic.
    |
    */

    'only' => [
        // 'hypervel/doctor',
        // 'vendor/*',
        // 'security',
    ],

    'except' => [
        // \Hypervel\Doctor\Diagnostics\EnvironmentFileIsGitIgnored::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Modes
    |--------------------------------------------------------------------------
    |
    | Group Hypervel environment names by the Doctor mode whose expectations
    | should apply. Any environment not listed here is treated as
    | production, the strictest mode.
    |
    */

    'environments' => [
        'local' => ['local'],
        'production' => ['production', 'staging'],
    ],
];
