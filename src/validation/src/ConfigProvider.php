<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Contracts\Validation\Factory as FactoryContract;
use Hypervel\Contracts\Validation\UncompromisedVerifier;

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
