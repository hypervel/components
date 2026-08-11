<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Json;
use ReflectionClass;

class ExtractProperties
{
    /**
     * Extract the properties for the given object in array form.
     *
     * The given array is ready for storage.
     */
    public static function from(mixed $target): array
    {
        // Native encoding captures reflected state instead of an object's published representation.
        return Collection::make((new ReflectionClass($target))->getProperties())
            ->mapWithKeys(function ($property) use ($target) {
                if (! $property->isInitialized($target)) {
                    return [];
                }

                if (($value = $property->getValue($target)) instanceof Model) {
                    return [$property->getName() => FormatModel::given($value)];
                }
                if (is_object($value)) {
                    return [
                        $property->getName() => [
                            'class' => get_class($value),
                            'properties' => method_exists($value, 'formatForTelescope')
                                ? $value->formatForTelescope()
                                : Json::decode(json_encode($value, JSON_THROW_ON_ERROR)),
                        ],
                    ];
                }
                return [$property->getName() => Json::decode(json_encode($value, JSON_THROW_ON_ERROR))];
            })->toArray();
    }
}
