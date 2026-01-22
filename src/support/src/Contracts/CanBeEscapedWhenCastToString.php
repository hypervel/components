<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

interface CanBeEscapedWhenCastToString
{
    /**
     * Indicate that the object's string representation should be escaped when __toString is invoked.
     *
     * @return $this
     */
    public function escapeWhenCastingToString(bool $escape = true): static;
}
