<?php

declare(strict_types=1);

namespace Hypervel\JWT\Validations;

use Carbon\CarbonInterface;
use Hypervel\JWT\Contracts\ValidationContract;
use Hypervel\Support\Facades\Date;

abstract class AbstractValidation implements ValidationContract
{
    public function __construct(
        protected array $config = []
    ) {
    }

    abstract public function validate(array $payload): void;

    protected function timestamp(int $timestamp): CarbonInterface
    {
        return Date::createFromTimestamp($timestamp);
    }
}
