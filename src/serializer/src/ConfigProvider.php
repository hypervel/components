<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use Hypervel\Contracts\Serializer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;

class ConfigProvider
{
    /**
     * Register the serializer dependencies.
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Serializer::class => SerializerFactory::class,
                NormalizerInterface::class => SimpleNormalizer::class,
            ],
        ];
    }
}
