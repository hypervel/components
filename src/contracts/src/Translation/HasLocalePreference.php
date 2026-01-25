<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Translation;

interface HasLocalePreference
{
    /**
     * Get the preferred locale of the entity.
     */
    public function preferredLocale(): ?string;
}
