<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Contracts\Support\Arrayable;
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
     * Normalize a value with the first custom normalizer that reads it, or return null.
     *
     * Null and already-normalized values never reach custom normalizers.
     *
     * @param list<Normalizer> $normalizers
     */
    public static function normalize(mixed $value, array $normalizers): array|Normalized|null
    {
        if ($value === null || $value instanceof Normalized) {
            return null;
        }

        foreach ($normalizers as $normalizer) {
            $normalized = $normalizer->normalize($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Resolve a value through the fixed source handling, or return null when nothing can read it.
     */
    public static function resolve(mixed $value): array|Normalized|null
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof Normalized) {
            return $value;
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

        return null;
    }
}
