<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class CannotCreateAbstractClass extends Exception
{
    /**
     * Create an exception for an unresolved property morph.
     *
     * @param class-string $originalClass
     */
    public static function morphClassWasNotResolved(
        string $originalClass,
    ): self {
        return new self(
            "Could not create abstract data class [{$originalClass}]: its morph method did not resolve a class."
        );
    }

    /**
     * Create an exception for an invalid property morph class.
     *
     * @param class-string $originalClass
     * @param class-string $resolvedClass
     */
    public static function invalidMorphClass(string $originalClass, string $resolvedClass): self
    {
        return new self(
            "Could not create abstract data class [{$originalClass}]: morph class [{$resolvedClass}] must be a "
            . 'concrete data class extending the abstract class.'
        );
    }
}
