<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Hypervel\Data\Exceptions\CannotCreateTransformerAttribute;
use Hypervel\Data\Transformers\Transformer;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class WithTransformer
{
    /** @var list<mixed> */
    public readonly array $arguments;

    /**
     * Create a new transformer attribute.
     *
     * @param class-string<Transformer> $transformerClass
     */
    public function __construct(
        public readonly string $transformerClass,
        mixed ...$arguments,
    ) {
        if (! is_a($this->transformerClass, Transformer::class, true)) {
            throw CannotCreateTransformerAttribute::notATransformer($this->transformerClass);
        }

        $this->arguments = $arguments;
    }

    /**
     * Get the configured transformer.
     */
    public function get(): Transformer
    {
        return new ($this->transformerClass)(...$this->arguments);
    }
}
