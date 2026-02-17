<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;

class PresenceVerifierFactory
{
    public function __invoke(Container $container): DatabasePresenceVerifier
    {
        return new DatabasePresenceVerifier(
            $container->make(ConnectionResolverInterface::class)
        );
    }
}
