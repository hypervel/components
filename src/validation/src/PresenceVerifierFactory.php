<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Contracts\Container\Container;

class PresenceVerifierFactory
{
    public function __invoke(Container $container): DatabasePresenceVerifier
    {
        return new DatabasePresenceVerifier(
            $container->get(ConnectionResolverInterface::class)
        );
    }
}
