<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Exceptions\CannotCreateCastAttribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class WithCast implements GetsCast
{
    /** @var list<mixed> */
    public readonly array $arguments;

    /**
     * Create a new cast attribute.
     *
     * @param class-string<Cast> $castClass
     */
    public function __construct(
        public readonly string $castClass,
        mixed ...$arguments,
    ) {
        if (! is_a($this->castClass, Cast::class, true)) {
            throw CannotCreateCastAttribute::notACast($this->castClass);
        }

        $this->arguments = $arguments;
    }

    /**
     * Get the configured cast.
     */
    public function get(): Cast
    {
        return new ($this->castClass)(...$this->arguments);
    }
}
