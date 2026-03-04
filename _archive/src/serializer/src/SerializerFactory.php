<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class SerializerFactory
{
    /**
     * Create a new serializer factory instance.
     */
    public function __construct(protected string $serializer = Serializer::class)
    {
    }

    /**
     * Create a new Symfony serializer with the default normalizers.
     */
    public function __invoke(): Serializer
    {
        return new $this->serializer([
            new ExceptionNormalizer(),
            new ObjectNormalizer(),
            new ArrayDenormalizer(),
            new ScalarNormalizer(),
        ]);
    }
}
