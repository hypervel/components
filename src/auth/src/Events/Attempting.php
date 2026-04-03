<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use SensitiveParameter;

class Attempting
{
    /**
     * Create a new event instance.
     *
     * @param string $guard the authentication guard name
     * @param array $credentials the credentials for the user
     * @param bool $remember indicates if the user should be "remembered"
     */
    public function __construct(
        public string $guard,
        #[SensitiveParameter]
        public array $credentials,
        public bool $remember,
    ) {
    }
}
