<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use ArrayObject;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

use function is_scalar;

class ScalarNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * Denormalize data by casting to the specified scalar type.
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        return match ($type) {
            'int' => (int) $data,
            'string' => (string) $data,
            'float' => (float) $data,
            'bool' => (bool) $data,
            default => $data,
        };
    }

    /**
     * Check whether the given type is a supported scalar type for denormalization.
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return in_array($type, [
            'int',
            'string',
            'float',
            'bool',
            'mixed',
            'array', // Symfony's ArrayDenormalizer does not support plain 'array', so it is denormalized here.
        ]);
    }

    /**
     * Normalize scalar data by returning it unchanged.
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|ArrayObject|bool|float|int|string|null
    {
        return $object;
    }

    /**
     * Check whether the given data is a scalar for normalization.
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_scalar($data);
    }

    /**
     * Return the types supported by this normalizer.
     *
     * @return array<'*'|'object'|class-string|string, null|bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return ['*' => static::class === __CLASS__];
    }
}
