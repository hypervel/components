<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\NormalizedModel;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Support\Json;
use JsonException;

class SourceResolver
{
    /**
     * Resolve a value into one source consumed by the fill walk.
     *
     * @param class-string $dataClass
     * @param list<Normalizer> $normalizers
     */
    public static function resolve(
        string $dataClass,
        mixed $value,
        array $normalizers,
    ): array|Normalized {
        if ($value === null) {
            return [];
        }

        if ($value instanceof Normalized) {
            return $value;
        }

        foreach ($normalizers as $normalizer) {
            $normalized = $normalizer->normalize($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Model) {
            return new NormalizedModel($value);
        }

        if ($value instanceof Request) {
            return $value->all();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        if (is_string($value)) {
            try {
                $decoded = Json::decode($value);

                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (JsonException) {
            }
        }

        throw CannotCreateData::noNormalizerFound($dataClass, $value);
    }
}
