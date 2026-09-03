<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Annotations;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;

class DataIterableAnnotation
{
    /**
     * Create a parsed iterable annotation.
     *
     * @param class-string $declaringClass
     */
    public function __construct(
        public readonly string $containerType,
        public readonly TypeNode $itemType,
        public readonly string $declaringClass,
        public readonly ?string $property = null,
    ) {
    }
}
