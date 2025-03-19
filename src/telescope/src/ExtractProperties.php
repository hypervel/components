<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Model;
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
        return Collection::make((new ReflectionClass($target))->getProperties())
            ->mapWithKeys(function ($property) use ($target) {
                $property->setAccessible(true);

                if (PHP_VERSION_ID >= 70400 && ! $property->isInitialized($target)) {
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
                                : json_decode(json_encode($value), true),
                        ],
                    ];
                }
                return [$property->getName() => json_decode(json_encode($value), true)];
            })->toArray();
    }
}
