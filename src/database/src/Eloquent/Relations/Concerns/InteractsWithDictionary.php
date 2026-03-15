<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Concerns;

use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait InteractsWithDictionary
{
    /**
     * Get a dictionary key attribute - casting it to a string if necessary.
     *
     * @throws InvalidArgumentException
     */
    protected function getDictionaryKey(mixed $attribute): mixed
    {
        if (is_object($attribute)) {
            if (method_exists($attribute, '__toString')) {
                return $attribute->__toString();
            }

            if ($attribute instanceof UnitEnum) {
                return enum_value($attribute);
            }

            throw new InvalidArgumentException('Model attribute value is an object but does not have a __toString method.');
        }

        return $attribute;
    }
}
