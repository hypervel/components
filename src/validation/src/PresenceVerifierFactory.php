<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hyperf\Database\ConnectionResolverInterface;
use Psr\Container\ContainerInterface;

class PresenceVerifierFactory
{
    public function __invoke(ContainerInterface $container): DatabasePresenceVerifier
    {
        return new DatabasePresenceVerifier(
            $container->get(ConnectionResolverInterface::class)
        );
    }
}
