<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\HttpClient\Factory as HttpFactory;
use Hypervel\Contracts\Container\Container;

class UncompromisedVerifierFactory
{
    public function __invoke(Container $container): NotPwnedVerifier
    {
        return new NotPwnedVerifier(
            $container->get(HttpFactory::class)
        );
    }
}
