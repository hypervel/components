<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\HttpClient\Factory as HttpFactory;
use Psr\Container\ContainerInterface;

class UncompromisedVerifierFactory
{
    public function __invoke(ContainerInterface $container): NotPwnedVerifier
    {
        return new NotPwnedVerifier(
            $container->get(HttpFactory::class)
        );
    }
}
