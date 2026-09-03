<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use ArrayAccess;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Support\DataProperty;

class SourceReader
{
    /**
     * Read one property from a normalized source.
     *
     * @param non-empty-list<array-key> $path
     */
    public static function read(
        array|Normalized $source,
        array $path,
        DataProperty $property,
    ): mixed {
        if ($source instanceof Normalized) {
            $value = $source->getProperty((string) $path[0], $property);

            if ($value instanceof UnknownProperty || count($path) === 1) {
                return $value;
            }

            return self::readPath($value, $path, 1);
        }

        if (count($path) === 1) {
            return array_key_exists($path[0], $source)
                ? $source[$path[0]]
                : UnknownProperty::create();
        }

        return self::readPath($source, $path, 0);
    }

    /**
     * Traverse literal path segments through arrays and accessible objects.
     *
     * @param non-empty-list<array-key> $path
     */
    protected static function readPath(mixed $value, array $path, int $offset): mixed
    {
        $count = count($path);

        for ($index = $offset; $index < $count; ++$index) {
            $segment = $path[$index];

            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            if ($value instanceof ArrayAccess && $value->offsetExists($segment)) {
                $value = $value[$segment];

                continue;
            }

            if (is_object($value)) {
                $name = (string) $segment;

                if (isset($value->{$name})) {
                    $value = $value->{$name};

                    continue;
                }

                $properties = get_object_vars($value);

                if (array_key_exists($name, $properties)) {
                    $value = $properties[$name];

                    continue;
                }
            }

            return UnknownProperty::create();
        }

        return $value;
    }
}
