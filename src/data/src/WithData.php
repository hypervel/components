<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotFindDataClass;

/**
 * @template TData of BaseData
 */
trait WithData
{
    /**
     * Get the associated data object.
     *
     * @return TData
     */
    public function getData(): BaseData
    {
        $dataClass = match (true) {
            property_exists($this, 'dataClass') => $this->dataClass,
            method_exists($this, 'dataClass') => $this->dataClass(),
            default => null,
        };

        if (! is_string($dataClass) || ! is_a($dataClass, BaseData::class, true)) {
            throw CannotFindDataClass::forSource(static::class, $dataClass);
        }

        /** @var class-string<TData> $dataClass */
        return $dataClass::from($this);
    }
}
