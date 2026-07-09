<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

interface ValidationContract
{
    /**
     * Validate the payload.
     */
    public function validate(array $payload): void;
}
