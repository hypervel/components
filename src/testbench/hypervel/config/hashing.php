<?php

declare(strict_types=1);

$bcryptLimit = env('BCRYPT_LIMIT');

return [
    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Here you may specify the configuration options that should be used when
    | passwords are hashed using the Bcrypt algorithm. This will allow you
    | to control the amount of time it takes to hash the given password.
    |
    */

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 10),
        'verify' => (bool) env('HASH_VERIFY', true),
        'limit' => $bcryptLimit === null ? null : (int) $bcryptLimit,
    ],
];
