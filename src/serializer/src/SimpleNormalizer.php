<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use Hypervel\Contracts\Serializer\NormalizerInterface;

class SimpleNormalizer implements NormalizerInterface
{
    /**
     * Normalize the given object by returning it unchanged.
     */
    public function normalize(mixed $object): mixed
    {
        return $object;
    }

    /**
     * Denormalize the given data by casting to the specified type.
     */
    public function denormalize(mixed $data, string $class): mixed
    {
        return match ($class) {
            'int' => (int) $data,
            'string' => (string) $data,
            'float' => (float) $data,
            'array' => (array) $data,
            'bool' => (bool) $data,
            default => $data,
        };
    }
}
