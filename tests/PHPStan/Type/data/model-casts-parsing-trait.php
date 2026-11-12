<?php

declare(strict_types=1);

namespace ModelCastsParsing;

/**
 * Declared apart from the model using it, so its casts() is read from this file.
 */
trait CastsIntegerAsFloat
{
    protected function casts(): array
    {
        return [
            'integer' => 'float',
        ];
    }
}
