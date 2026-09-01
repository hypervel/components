<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Support\DataProperty;

use function data_get;

class SourceReader
{
    /**
     * Read one property from a normalized source.
     */
    public static function read(
        array|Normalized $source,
        string|int $key,
        DataProperty $property,
    ): mixed {
        if ($source instanceof Normalized) {
            $segments = explode('.', (string) $key);
            $value = $source->getProperty(array_shift($segments), $property);

            if ($value instanceof UnknownProperty || $segments === []) {
                return $value;
            }

            return data_get($value, implode('.', $segments), UnknownProperty::create());
        }

        if (is_int($key)) {
            return array_key_exists($key, $source)
                ? $source[$key]
                : UnknownProperty::create();
        }

        return data_get($source, $key, UnknownProperty::create());
    }

    /**
     * Read the first source that contains a property.
     *
     * @param array<int, array|Normalized> $sources
     */
    public static function readFromMany(
        array $sources,
        string|int $key,
        DataProperty $property,
    ): mixed {
        foreach ($sources as $source) {
            $value = self::read($source, $key, $property);

            if ($value instanceof UnknownProperty) {
                continue;
            }

            return $value;
        }

        return UnknownProperty::create();
    }
}
