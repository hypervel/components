<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\References;

interface ExternalReference
{
    /**
     * Resolve the external rule parameter value.
     */
    public function getValue(): mixed;
}
