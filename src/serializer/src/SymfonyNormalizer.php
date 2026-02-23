<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use Hypervel\Contracts\Serializer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;

class SymfonyNormalizer implements NormalizerInterface
{
    /**
     * Create a new Symfony normalizer instance.
     */
    public function __construct(protected Serializer $serializer)
    {
    }

    /**
     * Normalize the given object using the Symfony serializer.
     */
    public function normalize(mixed $object): mixed
    {
        return $this->serializer->normalize($object);
    }

    /**
     * Denormalize the given data using the Symfony serializer.
     */
    public function denormalize(mixed $data, string $class): mixed
    {
        return $this->serializer->denormalize($data, $class);
    }
}
