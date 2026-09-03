<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\References;

use Hypervel\Data\Support\Validation\ValidationPath;

class FieldReference
{
    /**
     * Create a field reference.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $fromRoot = false,
    ) {
    }

    /**
     * Resolve the field name at the current validation path.
     */
    public function getValue(ValidationPath $path): string
    {
        return $this->fromRoot
            ? $this->name
            : $path->property($this->name)->get();
    }
}
