<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Throwable;

class UnserializeCast implements Cast
{
    /**
     * Create an unserialize cast.
     *
     * @param array<int, class-string>|bool $allowedClasses
     */
    public function __construct(
        private readonly bool $failSilently = false,
        // Spatie allows every class by default. Hypervel restores no objects unless their
        // classes are allowed, so unserializing runs no __wakeup() or __destruct() by default.
        private readonly array|bool $allowedClasses = false,
    ) {
    }

    /**
     * Cast a property value by unserializing it.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        /** @var ?string $value */
        if ($value === null) {
            return null;
        }

        try {
            return unserialize($value, ['allowed_classes' => $this->allowedClasses]);
        } catch (Throwable $e) {
            // The restore hooks of allowed classes can suspend, so cancellation is not a failed cast.
            if ($this->failSilently && ! Coroutine::causedByCancellation($e)) {
                return Uncastable::create();
            }

            throw $e;
        }
    }
}
