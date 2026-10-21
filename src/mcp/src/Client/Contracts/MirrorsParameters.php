<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Contracts;

interface MirrorsParameters
{
    /**
     * Get the headers mirroring method parameters.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array;
}
