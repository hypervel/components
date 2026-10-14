<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Container\Transient;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

class User implements UserEntityInterface, Transient
{
    use EntityTrait;

    /**
     * Create a new user instance.
     *
     * @param non-empty-string $identifier
     */
    public function __construct(string $identifier)
    {
        $this->setIdentifier($identifier);
    }
}
