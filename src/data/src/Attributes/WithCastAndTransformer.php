<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Exceptions\CannotCreateCastAttribute;
use Hypervel\Data\Exceptions\CannotCreateTransformerAttribute;
use Hypervel\Data\Transformers\Transformer;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class WithCastAndTransformer implements GetsCast
{
    /** @var list<mixed> */
    public readonly array $arguments;

    /**
     * Create a new cast and transformer attribute.
     *
     * @param class-string<Cast&Transformer> $class
     */
    public function __construct(
        public readonly string $class,
        mixed ...$arguments,
    ) {
        if (! is_a($this->class, Transformer::class, true)) {
            throw CannotCreateTransformerAttribute::notATransformer($this->class);
        }

        if (! is_a($this->class, Cast::class, true)) {
            throw CannotCreateCastAttribute::notACast($this->class);
        }

        $this->arguments = $arguments;
    }

    /**
     * Get the configured cast and transformer.
     */
    public function get(): Cast&Transformer
    {
        return new ($this->class)(...$this->arguments);
    }
}
