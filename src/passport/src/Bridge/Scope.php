<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Container\Transient;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

class Scope implements ScopeEntityInterface, Transient
{
    use ScopeTrait;
    use EntityTrait;

    /**
     * Create a new scope instance.
     *
     * @param non-empty-string $name
     */
    public function __construct(string $name)
    {
        $this->setIdentifier($name);
    }
}
