<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Data\Http\DataRequestCast;
use InvalidArgumentException;

trait RequestCastableData
{
    /**
     * Get the request caster for the data object.
     *
     * @param string[] $arguments
     */
    public static function castRequestUsing(array $arguments): CastsRequestInput
    {
        if ($arguments !== []) {
            throw new InvalidArgumentException(
                'Data object request cast [' . static::class . '] does not accept arguments.',
            );
        }

        return new DataRequestCast(static::class);
    }
}
