<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

interface ProvidesResourceWrapper
{
    /**
     * Get the wrapper for this resource instance.
     */
    public function resourceWrapper(): ?string;
}
