<?php

declare(strict_types=1);

/**
 * Workbench overrides for the foundation's base app config.
 *
 * Only keys that differ from src/foundation/config/app.php are listed here.
 * All other values are inherited from the base config via LoadConfiguration's
 * array_merge behavior.
 */
return [
    'env' => env('APP_ENV', 'dev'),

    'debug' => (bool) env('APP_DEBUG', false),

    'key' => env('APP_KEY', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF'),
];
