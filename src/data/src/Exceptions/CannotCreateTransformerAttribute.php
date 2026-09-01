<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Transformers\Transformer;

class CannotCreateTransformerAttribute extends Exception
{
    /**
     * Create an exception for an invalid transformer class.
     */
    public static function notATransformer(string $class): self
    {
        return new self("Transformer class [{$class}] must implement [" . Transformer::class . '].');
    }
}
