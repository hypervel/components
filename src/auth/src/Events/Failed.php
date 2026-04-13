<?php

declare(strict_types=1);

namespace Hypervel\Auth\Events;

use Hypervel\Contracts\Auth\Authenticatable;
use SensitiveParameter;

class Failed
{
    /**
     * Create a new event instance.
     *
     * @param string $guard the authentication guard name
     * @param ?Authenticatable $user the user the attempter was trying to authenticate as
     * @param array $credentials the credentials provided by the attempter
     */
    public function __construct(
        public string $guard,
        public ?Authenticatable $user,
        #[SensitiveParameter]
        public array $credentials,
    ) {
    }
}
