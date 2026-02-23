<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Serializer;

use ArrayObject;

interface NormalizerInterface
{
    /**
     * Normalize an object into a set of arrays/scalars.
     *
     * @return null|array|ArrayObject|bool|float|int|string
     */
    public function normalize(mixed $object): mixed;

    /**
     * Denormalize data back into an object of the given class.
     */
    public function denormalize(mixed $data, string $class): mixed;
}
