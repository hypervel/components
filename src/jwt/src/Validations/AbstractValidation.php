<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Validations;

use Carbon\CarbonInterface;
use Hypervel\Jwt\Contracts\ValidationContract;
use Hypervel\Support\Facades\Date;

abstract class AbstractValidation implements ValidationContract
{
    /**
     * Create a new validation instance.
     */
    public function __construct(
        protected array $config = []
    ) {
    }

    /**
     * Validate the payload.
     */
    abstract public function validate(array $payload): void;

    /**
     * Create a date from a claim timestamp.
     */
    protected function timestamp(int $timestamp): CarbonInterface
    {
        return Date::createFromTimestamp($timestamp);
    }
}
