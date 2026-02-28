<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use Hypervel\Contracts\Serializer\NormalizerInterface;

class JsonDeNormalizer implements NormalizerInterface
{
    /**
     * Normalize the given object.
     */
    public function normalize(mixed $object): mixed
    {
        return $object;
    }

    /**
     * Denormalize the given data into the specified class.
     */
    public function denormalize(mixed $data, string $class): mixed
    {
        return match ($class) {
            'int' => (int) $data,
            'string' => (string) $data,
            'float' => (float) $data,
            'array' => (array) $data,
            'bool' => (bool) $data,
            'mixed' => $data,
            default => $this->from($data, $class),
        };
    }

    /**
     * Attempt to deserialize using the class's jsonDeSerialize method.
     */
    private function from(mixed $data, string $class): mixed
    {
        if (method_exists($class, 'jsonDeSerialize')) {
            return $class::jsonDeSerialize($data);
        }

        return $data;
    }
}
