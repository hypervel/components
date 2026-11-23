<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Collection;

class ExtractProperties
{
    /**
     * Extract the properties for the given object in array form.
     *
     * The given array is ready for storage.
     */
    public static function from(mixed $target): array
    {
        $names = [];

        // Native encoding captures reflected state instead of an object's published representation.
        // Unlike Laravel, a parent's private properties are extracted too. One whose name an earlier
        // property already uses is keyed by its declaring class.
        return Collection::make(ClassMetadataCache::allProperties($target))
            ->mapWithKeys(function ($property) use ($target, &$names) {
                if (! $property->isInitialized($target)) {
                    return [];
                }

                $name = isset($names[$property->getName()])
                    ? $property->class . '::$' . $property->getName()
                    : $property->getName();
                $names[$property->getName()] = true;

                if (($value = $property->getValue($target)) instanceof Model) {
                    return [$name => FormatModel::given($value)];
                }
                if (is_object($value)) {
                    return [
                        $name => [
                            'class' => get_class($value),
                            'properties' => method_exists($value, 'formatForTelescope')
                                ? $value->formatForTelescope()
                                : JsonNormalizer::normalize($value),
                        ],
                    ];
                }
                return [$name => JsonNormalizer::normalize($value)];
            })->toArray();
    }
}
