<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

/**
 * Retain one translated rule path and its collection-wide identity.
 */
final readonly class TranslatedValidationPath
{
    /**
     * Create a translated validation path.
     */
    public function __construct(
        public ValidationPath $path,
        public ValidationPath $structuralPath,
    ) {
    }
}
