<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Exceptions\CannotCreateCastAttribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class WithCastable implements GetsCast
{
    /** @var list<mixed> */
    public readonly array $arguments;

    /**
     * Create a new castable attribute.
     *
     * @param class-string<Castable> $castableClass
     */
    public function __construct(
        public readonly string $castableClass,
        mixed ...$arguments,
    ) {
        if (! is_a($this->castableClass, Castable::class, true)) {
            throw CannotCreateCastAttribute::notACastable($this->castableClass);
        }

        $this->arguments = $arguments;
    }

    /**
     * Get the configured cast.
     */
    public function get(): Cast
    {
        return $this->castableClass::dataCastUsing($this->arguments);
    }
}
