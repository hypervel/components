<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Data;

use Throwable;

/**
 * @template TResult
 */
class TokenAcquisitionResult
{
    public bool $completed = false;

    /** @var null|TResult */
    public mixed $value = null;

    public ?Throwable $error = null;
}
