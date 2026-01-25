<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Contracts\Translation\Translator;
use Psr\Container\ContainerInterface;

class ValidatorFactory
{
    public function __invoke(ContainerInterface $container): Factory
    {
        $validator = new Factory(
            $container->get(Translator::class),
            $container
        );

        // The validation presence verifier is responsible for determining the existence of
        // values in a given data collection which is typically a relational database or
        // other persistent data stores. It is used to check for "uniqueness" as well.
        if ($container->has(ConnectionResolverInterface::class) && $container->has(DatabasePresenceVerifierInterface::class)) {
            $presenceVerifier = $container->get(DatabasePresenceVerifierInterface::class);
            $validator->setPresenceVerifier($presenceVerifier);
        }

        return $validator;
    }
}
