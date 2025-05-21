<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Validation\Contracts\Factory as FactoryContract;
use Hypervel\Validation\Contracts\UncompromisedVerifier;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                FactoryContract::class => ValidatorFactory::class,
                DatabasePresenceVerifierInterface::class => PresenceVerifierFactory::class,
                UncompromisedVerifier::class => UncompromisedVerifierFactory::class,
            ],
        ];
    }
}
